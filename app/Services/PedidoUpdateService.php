<?php

namespace App\Services;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\PedidoStatus;
use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueReserva;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\ProdutoVariacao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PedidoUpdateService
{
    public function __construct(
        private readonly EstoqueMovimentacaoService $movimentacoes,
        private readonly ReservaEstoqueService $reservas,
        private readonly EstoqueDisponibilidadeService $disponibilidade,
        private readonly PedidoPrazoService $prazoService
    ) {}

    public function atualizar(Pedido $pedido, array $dados, int $usuarioId): Pedido
    {
        return DB::transaction(function () use ($pedido, $dados, $usuarioId) {
            $this->assertPedidoEditavel($pedido);

            $itensPayload = collect($dados['itens'] ?? []);
            if ($itensPayload->isEmpty()) {
                throw ValidationException::withMessages([
                    'itens' => ['Informe ao menos um item para o pedido.']
                ]);
            }

            $itensExistentes = $pedido->itens()->get()->keyBy('id');

            foreach ($itensPayload as $item) {
                if (!empty($item['id']) && !$itensExistentes->has($item['id'])) {
                    throw ValidationException::withMessages([
                        'itens' => ["Item {$item['id']} nÃ£o pertence a este pedido."]
                    ]);
                }
            }

            $variacaoIds = $itensPayload->pluck('id_variacao')->unique()->values();
            $variacoes = ProdutoVariacao::query()
                ->whereIn('id', $variacaoIds)
                ->get()
                ->keyBy('id');

            $itensNormalizados = $itensPayload->map(function ($item) use ($variacoes, $itensExistentes) {
                $variacao = $variacoes->get((int) $item['id_variacao']);
                $precoUnitario = array_key_exists('preco_unitario', $item)
                    ? (float) $item['preco_unitario']
                    : (float) ($variacao?->preco ?? 0);

                $quantidade = (int) $item['quantidade'];
                $subtotal = $precoUnitario * $quantidade;
                $itemId = $item['id'] ?? null;
                $idDeposito = array_key_exists('id_deposito', $item)
                    ? $item['id_deposito']
                    : ($itemId ? $itensExistentes->get($itemId)?->id_deposito : null);

                return [
                    'id' => $itemId ? (int) $itemId : null,
                    'id_variacao' => (int) $item['id_variacao'],
                    'quantidade' => $quantidade,
                    'preco_unitario' => $precoUnitario,
                    'subtotal' => $subtotal,
                    'id_deposito' => $idDeposito ? (int) $idDeposito : null,
                ];
            });

            $idsManter = $itensNormalizados->pluck('id')->filter()->values();
            $idsRemover = $itensExistentes->keys()->diff($idsManter);

            if ($idsRemover->isNotEmpty()) {
                PedidoItem::query()->whereIn('id', $idsRemover)->delete();
            }

            foreach ($itensNormalizados as $item) {
                if ($item['id'] && $itensExistentes->has($item['id'])) {
                    $itensExistentes[$item['id']]->update([
                        'id_variacao' => $item['id_variacao'],
                        'quantidade' => $item['quantidade'],
                        'preco_unitario' => $item['preco_unitario'],
                        'subtotal' => $item['subtotal'],
                        'id_deposito' => $item['id_deposito'],
                    ]);
                } else {
                    PedidoItem::create([
                        'id_pedido' => $pedido->id,
                        'id_variacao' => $item['id_variacao'],
                        'quantidade' => $item['quantidade'],
                        'preco_unitario' => $item['preco_unitario'],
                        'subtotal' => $item['subtotal'],
                        'id_deposito' => $item['id_deposito'],
                    ]);
                }
            }

            if (array_key_exists('id_cliente', $dados)) {
                $pedido->id_cliente = $dados['id_cliente'];
            }
            if (array_key_exists('id_parceiro', $dados)) {
                $pedido->id_parceiro = $dados['id_parceiro'];
            }
            if (array_key_exists('observacoes', $dados)) {
                $pedido->observacoes = $dados['observacoes'];
            }
            if (array_key_exists('prazo_dias_uteis', $dados)) {
                $pedido->prazo_dias_uteis = $dados['prazo_dias_uteis'];
            }

            $pedido->valor_total = (float) $itensNormalizados->sum('subtotal');
            $pedido->save();

            if (array_key_exists('prazo_dias_uteis', $dados)) {
                $this->prazoService->definirDataLimite($pedido, (int) $dados['prazo_dias_uteis']);
            }

            $pedido->load('itens');

            $movimentacoes = EstoqueMovimentacao::query()
                ->where('pedido_id', $pedido->id)
                ->where('tipo', '!=', EstoqueMovimentacaoTipo::ESTORNO->value)
                ->get();

            $hasMovimentacoes = $movimentacoes->isNotEmpty();
            $hasReservas = EstoqueReserva::query()
                ->where('pedido_id', $pedido->id)
                ->where('status', 'ativa')
                ->exists();

            if ($hasMovimentacoes) {
                foreach ($movimentacoes as $mov) {
                    $jaEstornado = EstoqueMovimentacao::query()
                        ->where('ref_type', 'estorno')
                        ->where('ref_id', $mov->id)
                        ->exists();
                    if ($jaEstornado) {
                        continue;
                    }
                    $this->movimentacoes->estornarMovimentacao(
                        (int) $mov->id,
                        $usuarioId,
                        'EdiÃ§Ã£o do pedido'
                    );
                }
            }

            if ($hasReservas) {
                $this->reservas->cancelarPorPedido((int) $pedido->id, $usuarioId, 'pedido_editado');
            }

            if ($hasMovimentacoes || $hasReservas) {
                $this->validarDisponibilidade($pedido->itens);
            }

            if ($hasMovimentacoes) {
                $loteId = (string) Str::uuid();
                foreach ($pedido->itens as $item) {
                    $depId = (int) $item->id_deposito;
                    $this->movimentacoes->registrarSaidaPedido(
                        variacaoId: (int) $item->id_variacao,
                        depositoSaidaId: $depId,
                        quantidade: (int) $item->quantidade,
                        usuarioId: (int) $usuarioId,
                        observacao: "Pedido #{$pedido->id} (ediÃ§Ã£o)",
                        pedidoId: (int) $pedido->id,
                        pedidoItemId: (int) $item->id,
                        loteId: $loteId,
                    );
                }
            } elseif ($hasReservas) {
                foreach ($pedido->itens as $item) {
                    $depId = (int) $item->id_deposito;
                    $this->reservas->reservar(
                        variacaoId: (int) $item->id_variacao,
                        depositoId: $depId,
                        quantidade: (int) $item->quantidade,
                        pedidoId: (int) $pedido->id,
                        pedidoItemId: (int) $item->id,
                        usuarioId: (int) $usuarioId,
                        motivo: 'pedido_editado'
                    );
                }
            }

            return $pedido->refresh();
        });
    }

    private function assertPedidoEditavel(Pedido $pedido): void
    {
        $pedido->loadMissing('statusAtual');

        $status = $pedido->statusAtual?->status;
        $statusValue = $status instanceof PedidoStatus ? $status->value : (string) $status;

        $bloqueados = [
            PedidoStatus::ENTREGA_CLIENTE->value,
            PedidoStatus::FINALIZADO->value,
            PedidoStatus::CONSIGNADO->value,
            PedidoStatus::DEVOLUCAO_CONSIGNACAO->value,
        ];

        if ($statusValue && in_array($statusValue, $bloqueados, true)) {
            throw ValidationException::withMessages([
                'pedido' => ["Pedido com status '{$statusValue}' nÃ£o pode ser editado."]
            ]);
        }

        if ($pedido->consignacoes()->exists()) {
            throw ValidationException::withMessages([
                'pedido' => ['Pedido consignado nÃ£o pode ser editado.']
            ]);
        }

        if ($pedido->devolucoes()->exists()) {
            throw ValidationException::withMessages([
                'pedido' => ['Pedido com devoluÃ§Ãµes nÃ£o pode ser editado.']
            ]);
        }
    }

    private function validarDisponibilidade(Collection $itens): void
    {
        $erros = [];

        foreach ($itens as $item) {
            $depId = (int) ($item->id_deposito ?? 0);
            if (!$depId) {
                $erros[] = "Item {$item->id}: selecione um depÃ³sito antes de ajustar o estoque.";
                continue;
            }

            $disponivel = $this->disponibilidade->getDisponivel((int) $item->id_variacao, $depId);
            if ($disponivel < (int) $item->quantidade) {
                $erros[] = "Item {$item->id}: estoque insuficiente no depÃ³sito {$depId}. DisponÃ­vel {$disponivel}, solicitado {$item->quantidade}.";
            }
        }

        if ($erros) {
            throw ValidationException::withMessages(['estoque' => $erros]);
        }
    }
}
