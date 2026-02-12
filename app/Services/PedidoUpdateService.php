<?php

namespace App\Services;

use App\Helpers\AuthHelper;
use App\Models\EstoqueMovimentacao;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\EstoqueReserva;
use App\Models\ProdutoVariacao;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PedidoUpdateService
{
    public function __construct(
        private readonly PedidoPrazoService $prazoService,
        private readonly ReservaEstoqueService $reservaService,
        private readonly EstoqueMovimentacaoService $movimentacaoService,
    ) {}

    /**
     * Atualiza pedido e itens de forma transacional.
     *
     * @param Pedido $pedido
     * @param array<string,mixed> $data
     * @return Pedido
     */
    public function atualizar(Pedido $pedido, array $data): Pedido
    {
        return DB::transaction(function () use ($pedido, $data) {
            $itensInput = array_key_exists('itens', $data) ? (array) $data['itens'] : null;
            $itensAntigos = $pedido->itens()->get();

            $this->atualizarCabecalho($pedido, $data);

            $itensNovos = null;
            $diffs = null;

            if (is_array($itensInput)) {
                $sync = $this->sincronizarItens($pedido, $itensInput, $itensAntigos);
                $itensNovos = $sync['itens'];
                $diffs = $sync['diffs'];

                $pedido->valor_total = $sync['total'];
                $pedido->save();
            }

            if (is_array($itensInput)) {
                $this->ajustarReservasSeNecessario($pedido, $itensNovos);
                $this->ajustarMovimentacoesSeNecessario($pedido, $diffs);
            }

            return $pedido->fresh([
                'cliente:id,nome,email,telefone',
                'parceiro:id,nome',
                'usuario:id,nome',
                'statusAtual',
                'itens.variacao.produto.imagens',
                'itens.variacao.atributos',
                'historicoStatus.usuario:id,nome',
                'devolucoes.itens.pedidoItem.variacao.produto',
                'devolucoes.itens.trocaItens.variacaoNova.produto',
                'devolucoes.credito',
            ]);
        });
    }

    /**
     * @param Pedido $pedido
     * @param array<string,mixed> $data
     * @return void
     */
    private function atualizarCabecalho(Pedido $pedido, array $data): void
    {
        $permitidos = [
            'tipo',
            'id_cliente',
            'id_parceiro',
            'numero_externo',
            'observacoes',
            'prazo_dias_uteis',
            'data_limite_entrega',
        ];

        $payload = Arr::only($data, $permitidos);

        if (array_key_exists('data_pedido', $data)) {
            $payload['data_pedido'] = $data['data_pedido']
                ? Carbon::parse($data['data_pedido'])->timezone('America/Belem')
                : null;
        }

        if (array_key_exists('id_usuario', $data)) {
            if (!AuthHelper::hasPermissao('pedidos.selecionar_vendedor')) {
                throw ValidationException::withMessages([
                    'id_usuario' => ['Sem permissao para alterar vendedor.'],
                ]);
            }
            $payload['id_usuario'] = $data['id_usuario'];
        }

        $pedido->fill($payload);
        $pedido->save();

        $precisaRecalcularPrazo = array_key_exists('prazo_dias_uteis', $data)
            || array_key_exists('data_pedido', $data);

        if (!array_key_exists('data_limite_entrega', $data) && $precisaRecalcularPrazo) {
            $this->prazoService->definirDataLimite($pedido, $pedido->prazo_dias_uteis);
        }
    }

    /**
     * @param Pedido $pedido
     * @param array<int,array<string,mixed>> $itensInput
     * @param \Illuminate\Support\Collection<int,PedidoItem> $itensAntigos
     * @return array{itens: array<int,PedidoItem>, total: float, diffs: array<string,int>}
     */
    private function sincronizarItens(Pedido $pedido, array $itensInput, $itensAntigos): array
    {
        $existentes = $itensAntigos->keyBy('id');
        $manterIds = [];
        $itensAtualizados = [];

        $total = 0.0;

        foreach ($itensInput as $index => $item) {
            if (!is_array($item)) continue;

            $variacaoId = $this->resolverVariacaoId($item, $index);
            $quantidade = (int) ($item['quantidade'] ?? 0);
            if ($quantidade <= 0) {
                throw ValidationException::withMessages([
                    "itens.$index.quantidade" => ['Quantidade deve ser maior que zero.'],
                ]);
            }

            $preco = $this->normalizarMoney($item['preco_unitario'] ?? 0);
            $subtotal = round($quantidade * $preco, 2);
            $total += $subtotal;

            $payload = [
                'id_variacao' => $variacaoId,
                'quantidade' => $quantidade,
                'preco_unitario' => $preco,
                'subtotal' => $subtotal,
                'id_deposito' => $item['id_deposito'] ?? null,
                'observacoes' => $item['observacoes'] ?? null,
            ];

            $idItem = $item['id'] ?? null;
            if ($idItem) {
                if (!$existentes->has($idItem)) {
                    throw ValidationException::withMessages([
                        "itens.$index.id" => ['Item nao pertence a este pedido.'],
                    ]);
                }

                $itemModel = $existentes->get($idItem);
                $itemModel->update($payload);
                $manterIds[] = $itemModel->id;
                $itensAtualizados[] = $itemModel;
                continue;
            }

            $novo = $pedido->itens()->create($payload);
            $manterIds[] = $novo->id;
            $itensAtualizados[] = $novo;
        }

        if (!empty($manterIds)) {
            $pedido->itens()->whereNotIn('id', $manterIds)->delete();
        } else {
            $pedido->itens()->delete();
        }

        $diffs = $this->calcularDiferencasEstoque($itensAntigos, $itensAtualizados);

        return [
            'itens' => $itensAtualizados,
            'total' => $total,
            'diffs' => $diffs,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @param int $index
     * @return int
     */
    private function resolverVariacaoId(array $item, int $index): int
    {
        $variacaoId = $item['id_variacao'] ?? null;
        if ($variacaoId) {
            return (int) $variacaoId;
        }

        $produtoId = $item['id_produto'] ?? null;
        if (!$produtoId) {
            throw ValidationException::withMessages([
                "itens.$index.id_variacao" => ['Selecione uma variacao.'],
            ]);
        }

        $variacao = ProdutoVariacao::query()
            ->where('produto_id', (int) $produtoId)
            ->orderBy('id')
            ->first();

        if (!$variacao) {
            throw ValidationException::withMessages([
                "itens.$index.id_produto" => ['Produto sem variacoes disponiveis.'],
            ]);
        }

        return (int) $variacao->id;
    }

    private function normalizarMoney(mixed $valor): float
    {
        if (is_numeric($valor)) {
            return (float) $valor;
        }

        $texto = trim((string) $valor);
        if ($texto === '') {
            return 0.0;
        }

        if (str_contains($texto, ',')) {
            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        }

        return (float) $texto;
    }

    /**
     * @param \Illuminate\Support\Collection<int,PedidoItem> $antigos
     * @param array<int,PedidoItem> $novos
     * @return array<string,int>
     */
    private function calcularDiferencasEstoque($antigos, array $novos): array
    {
        $mapAntigo = [];
        foreach ($antigos as $item) {
            $key = $this->makeDiffKey((int) $item->id_variacao, (int) ($item->id_deposito ?? 0));
            $mapAntigo[$key] = ($mapAntigo[$key] ?? 0) + (int) $item->quantidade;
        }

        $mapNovo = [];
        foreach ($novos as $item) {
            $key = $this->makeDiffKey((int) $item->id_variacao, (int) ($item->id_deposito ?? 0));
            $mapNovo[$key] = ($mapNovo[$key] ?? 0) + (int) $item->quantidade;
        }

        $diffs = [];
        $keys = array_unique(array_merge(array_keys($mapAntigo), array_keys($mapNovo)));
        foreach ($keys as $key) {
            $diffs[$key] = ($mapNovo[$key] ?? 0) - ($mapAntigo[$key] ?? 0);
        }

        return $diffs;
    }

    private function makeDiffKey(int $variacaoId, int $depositoId): string
    {
        return $variacaoId . '|' . $depositoId;
    }

    /**
     * @param Pedido $pedido
     * @param array<int,PedidoItem>|null $itens
     * @return void
     */
    private function ajustarReservasSeNecessario(Pedido $pedido, ?array $itens): void
    {
        if (!$itens) return;

        $temReservasAtivas = EstoqueReserva::query()
            ->where('pedido_id', $pedido->id)
            ->where('status', 'ativa')
            ->exists();

        if (!$temReservasAtivas) return;

        foreach ($itens as $item) {
            if (empty($item->id_deposito)) {
                throw ValidationException::withMessages([
                    'itens' => ['Informe o deposito para atualizar reservas.'],
                ]);
            }
        }

        $usuarioId = (int) (AuthHelper::getUsuarioId() ?? 0);

        $this->reservaService->cancelarPorPedido((int) $pedido->id, $usuarioId, 'pedido_editado');

        foreach ($itens as $item) {
            $this->reservaService->reservar(
                variacaoId: (int) $item->id_variacao,
                depositoId: (int) $item->id_deposito,
                quantidade: (int) $item->quantidade,
                pedidoId: (int) $pedido->id,
                pedidoItemId: (int) $item->id,
                usuarioId: $usuarioId,
                motivo: 'pedido_editado'
            );
        }
    }

    /**
     * @param Pedido $pedido
     * @param array<string,int>|null $diffs
     * @return void
     */
    private function ajustarMovimentacoesSeNecessario(Pedido $pedido, ?array $diffs): void
    {
        if (!$diffs) return;

        $temMovimentacoes = EstoqueMovimentacao::query()
            ->where('pedido_id', $pedido->id)
            ->exists();

        if (!$temMovimentacoes) return;

        $usuarioId = (int) (AuthHelper::getUsuarioId() ?? 0);

        foreach ($diffs as $key => $diff) {
            if ($diff === 0) continue;

            [$variacaoId, $depositoId] = array_map('intval', explode('|', $key));
            if ($depositoId <= 0) {
                throw ValidationException::withMessages([
                    'itens' => ['Informe o deposito para ajustar estoque.'],
                ]);
            }

            if ($diff > 0) {
                $this->movimentacaoService->registrarSaidaPedido(
                    variacaoId: $variacaoId,
                    depositoSaidaId: $depositoId,
                    quantidade: $diff,
                    usuarioId: $usuarioId,
                    observacao: "Ajuste de edicao do pedido #{$pedido->id}",
                    pedidoId: (int) $pedido->id,
                    pedidoItemId: null,
                );
            } else {
                $this->movimentacaoService->registrarMovimentacaoManual([
                    'id_variacao' => $variacaoId,
                    'id_deposito_origem' => null,
                    'id_deposito_destino' => $depositoId,
                    'tipo' => 'entrada',
                    'quantidade' => abs($diff),
                    'id_usuario' => $usuarioId,
                    'observacao' => "Ajuste de edicao do pedido #{$pedido->id}",
                    'pedido_id' => (int) $pedido->id,
                ]);
            }
        }
    }
}
