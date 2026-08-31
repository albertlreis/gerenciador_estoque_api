<?php

namespace App\Services;

use App\Enums\PedidoStatus;
use App\Exceptions\PedidoConversaoRequerReconciliacaoException;
use App\Models\Estoque;
use App\Models\Pedido;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PedidoTipoConversaoService
{
    public const MODO_PENDENTE = 'entrega_pendente';
    public const MODO_CONFIRMADA = 'entrega_confirmada';

    public function __construct(
        private readonly EntregaProdutoService $entregas,
        private readonly EstoqueDisponibilidadeService $disponibilidade,
        private readonly AuditoriaEventoService $auditoria,
    ) {}

    /** @param array<string,mixed> $data */
    public function exigeReconciliacao(Pedido $pedido, array $data): bool
    {
        if (! $pedido->isReposicao() || ($data['tipo'] ?? null) !== Pedido::TIPO_VENDA) {
            return false;
        }

        return $this->itensPrincipais($pedido)->contains(fn (ProdutoEntregaItem $item) => max(
            (int) $item->quantidade_recebida,
            (int) $item->quantidade_reservada,
            (int) $item->quantidade_expedida,
            (int) $item->quantidade_entregue,
        ) > 0);
    }

    /**
     * @param array<string,mixed>|null $conversao
     * @param array<string,mixed> $data
     */
    public function validarOuFalhar(Pedido $pedido, ?array $conversao, array $data = []): void
    {
        if ($conversao === null) {
            throw new PedidoConversaoRequerReconciliacaoException($this->preview($pedido));
        }

        $modo = (string) ($conversao['modo'] ?? '');
        if (! in_array($modo, [self::MODO_PENDENTE, self::MODO_CONFIRMADA], true)) {
            throw ValidationException::withMessages([
                'conversao_fluxo.modo' => ['Escolha se a entrega ficará pendente ou se já foi confirmada.'],
            ]);
        }

        if (empty($data['id_cliente']) && empty($pedido->id_cliente)) {
            throw ValidationException::withMessages([
                'id_cliente' => ['Selecione o cliente antes de converter a reposição em venda.'],
            ]);
        }

        if ($modo === self::MODO_CONFIRMADA && empty($conversao['ocorrido_em'])) {
            throw ValidationException::withMessages([
                'conversao_fluxo.ocorrido_em' => ['Informe a data efetiva da entrega.'],
            ]);
        }
    }

    /** @return array<string,mixed> */
    public function preview(Pedido $pedido): array
    {
        return [
            'pedido_id' => (int) $pedido->id,
            'tipo_atual' => (string) $pedido->tipo,
            'tipo_destino' => Pedido::TIPO_VENDA,
            'modos' => [self::MODO_PENDENTE, self::MODO_CONFIRMADA],
            'itens' => $this->itensPrincipais($pedido)->map(function (ProdutoEntregaItem $item) {
                $depositos = $this->disponibilidade->getDisponiveisPorDeposito((int) $item->id_variacao);

                return [
                    'produto_entrega_item_id' => (int) $item->id,
                    'pedido_item_id' => $item->pedido_item_id ? (int) $item->pedido_item_id : null,
                    'id_variacao' => (int) $item->id_variacao,
                    'produto' => $item->variacao?->produto?->nome,
                    'quantidade_total' => (int) $item->quantidade_total,
                    'quantidade_recebida' => (int) $item->quantidade_recebida,
                    'quantidade_reservada' => (int) $item->quantidade_reservada,
                    'quantidade_expedida' => (int) $item->quantidade_expedida,
                    'quantidade_entregue' => (int) $item->quantidade_entregue,
                    'quantidade_pendente' => max(0, (int) $item->quantidade_total - (int) $item->quantidade_entregue),
                    'depositos' => $depositos,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param array<string,mixed> $conversao
     * @return array<string,mixed>
     */
    public function aplicar(Pedido $pedido, array $conversao, ?int $usuarioId): array
    {
        return DB::transaction(function () use ($pedido, $conversao, $usuarioId) {
            $pedido = Pedido::query()->whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $modo = (string) $conversao['modo'];
            $chave = trim((string) ($conversao['idempotency_key'] ?? ''));
            $ocorridoEm = ! empty($conversao['ocorrido_em'])
                ? Carbon::parse($conversao['ocorrido_em'], config('app.timezone'))
                : now();
            $saldosAntes = $this->snapshotSaldos($pedido);

            $auditoriaExistente = $chave !== ''
                ? DB::table('auditoria_logs')
                    ->where('acao', 'pedido.tipo_conversao_reconciliada')
                    ->where('entity_id', (string) $pedido->id)
                    ->where('metadata_json->idempotency_key', $chave)
                    ->first()
                : null;
            if ($auditoriaExistente) {
                $metadata = json_decode((string) $auditoriaExistente->metadata_json, true) ?: [];

                return [
                    'modo' => $metadata['modo'] ?? $modo,
                    'eventos' => $metadata['eventos'] ?? [],
                    'movimentacoes' => $metadata['movimentacoes'] ?? [],
                    'ocorrido_em' => $metadata['ocorrido_em'] ?? $ocorridoEm->toIso8601String(),
                    'saldos_antes' => $metadata['saldos_antes'] ?? [],
                    'saldos_depois' => $metadata['saldos_depois'] ?? [],
                ];
            }

            if ($modo === self::MODO_PENDENTE) {
                $this->adicionarStatus(
                    $pedido,
                    PedidoStatus::ENTREGA_PENDENTE->value,
                    $ocorridoEm,
                    $usuarioId,
                    'Pedido convertido de reposição para venda; entrega ao cliente mantida como pendente.'
                );

                $resultado = [
                    'modo' => $modo,
                    'ocorrido_em' => $ocorridoEm->toIso8601String(),
                    'eventos' => [],
                    'movimentacoes' => [],
                    'saldos_antes' => $saldosAntes,
                    'saldos_depois' => $this->snapshotSaldos($pedido),
                ];
                $this->registrarAuditoria($pedido, $resultado, $chave);

                return $resultado;
            }

            $itensPayload = collect($conversao['itens'] ?? [])->keyBy('produto_entrega_item_id');
            $itens = $this->itensPrincipais($pedido);
            $eventos = [];
            $movimentacoes = [];

            foreach ($itens as $item) {
                $pendente = max(0, (int) $item->quantidade_total - (int) $item->quantidade_entregue);
                if ($pendente === 0) {
                    continue;
                }

                $entrada = $itensPayload->get((int) $item->id);
                $quantidade = (int) ($entrada['quantidade'] ?? 0);
                $depositoId = (int) ($entrada['id_deposito'] ?? 0);

                if (! $entrada || $quantidade !== $pendente || $depositoId <= 0) {
                    throw ValidationException::withMessages([
                        'conversao_fluxo.itens' => ["O item operacional {$item->id} deve informar depósito e a quantidade pendente integral ({$pendente})."],
                    ]);
                }

                $liberadoRecebimento = max(0, (int) $item->quantidade_recebida - (int) $item->quantidade_entregue);
                if ($quantidade > $liberadoRecebimento) {
                    throw ValidationException::withMessages([
                        'conversao_fluxo.itens' => ["O item operacional {$item->id} ainda não foi integralmente recebido da fábrica."],
                    ]);
                }

                $jaExpedidoNaoEntregue = max(0, (int) $item->quantidade_expedida - (int) $item->quantidade_entregue);
                $quantidadeAExpedir = max(0, $quantidade - $jaExpedidoNaoEntregue);
                $disponivel = $this->disponibilidade->getDisponivel((int) $item->id_variacao, $depositoId);
                if ($disponivel < $quantidadeAExpedir) {
                    throw ValidationException::withMessages([
                        'conversao_fluxo.itens' => ["Saldo insuficiente no depósito {$depositoId} para o item operacional {$item->id}: {$disponivel} disponível para {$quantidadeAExpedir} a expedir."],
                    ]);
                }

                $baseKey = $chave.':item:'.$item->id;
                $expedido = $quantidadeAExpedir > 0
                    ? $this->entregas->expedirItem(
                        $item,
                        $depositoId,
                        $quantidadeAExpedir,
                        $usuarioId,
                        'Expedição registrada na conversão guiada de reposição para venda.',
                        ProdutoEntregaEvento::EXPEDIDO_CLIENTE,
                        $baseKey.':expedir',
                        ocorridoEm: $ocorridoEm,
                        metadata: ['origem' => 'conversao_tipo_guiada', 'modo' => $modo]
                    )
                    : $item;
                $entregue = $this->entregas->entregarItem(
                    $expedido,
                    $quantidade,
                    $usuarioId,
                    'Entrega registrada na conversão guiada de reposição para venda.',
                    $baseKey.':entregar',
                    ocorridoEm: $ocorridoEm,
                    metadata: ['origem' => 'conversao_tipo_guiada', 'modo' => $modo]
                );

                $novosEventos = $entregue->eventos()
                    ->whereIn('idempotency_key', [$baseKey.':expedir', $baseKey.':entregar'])
                    ->get();
                $eventos = [...$eventos, ...$novosEventos->pluck('id')->map(fn ($id) => (int) $id)->all()];
                $movimentacoes = [...$movimentacoes, ...$novosEventos->pluck('estoque_movimentacao_id')->filter()->map(fn ($id) => (int) $id)->all()];
            }

            $resumo = $this->entregas->resumoPedido($pedido->fresh('entregaItens'));
            if ((int) $resumo['quantidade_total'] <= 0 || (int) $resumo['quantidade_entregue'] < (int) $resumo['quantidade_total']) {
                throw ValidationException::withMessages([
                    'conversao_fluxo.itens' => ['A conversão confirmada deve concluir a entrega de todos os itens do pedido.'],
                ]);
            }

            $this->adicionarStatus($pedido, PedidoStatus::ENVIO_CLIENTE->value, $ocorridoEm, $usuarioId, 'Expedição reconciliada na conversão guiada do pedido.');
            $this->adicionarStatus($pedido, PedidoStatus::ENTREGA_CLIENTE->value, $ocorridoEm, $usuarioId, 'Entrega reconciliada na conversão guiada do pedido.');
            $this->adicionarStatus($pedido, PedidoStatus::FINALIZADO->value, $ocorridoEm, $usuarioId, 'Pedido finalizado após reconciliação da conversão para venda.');

            $resultado = [
                'modo' => $modo,
                'ocorrido_em' => $ocorridoEm->toIso8601String(),
                'eventos' => array_values(array_unique($eventos)),
                'movimentacoes' => array_values(array_unique($movimentacoes)),
                'saldos_antes' => $saldosAntes,
                'saldos_depois' => $this->snapshotSaldos($pedido),
            ];
            $this->registrarAuditoria($pedido, $resultado, $chave);

            return $resultado;
        });
    }

    /** @return Collection<int,ProdutoEntregaItem> */
    private function itensPrincipais(Pedido $pedido): Collection
    {
        return $pedido->entregaItens()
            ->with('variacao.produto')
            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
            ->where('status', '!=', ProdutoEntregaItem::STATUS_CANCELADO)
            ->orderBy('id')
            ->get();
    }

    private function adicionarStatus(Pedido $pedido, string $status, Carbon $data, ?int $usuarioId, string $observacao): void
    {
        $pedido->historicoStatus()->create([
            'status' => $status,
            'data_status' => $data,
            'usuario_id' => $usuarioId,
            'observacoes' => $observacao,
        ]);
    }

    /** @return array<int,array{id_variacao:int,id_deposito:int,quantidade:int}> */
    private function snapshotSaldos(Pedido $pedido): array
    {
        $variacoes = $this->itensPrincipais($pedido)
            ->pluck('id_variacao')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($variacoes->isEmpty()) {
            return [];
        }

        return Estoque::query()
            ->whereIn('id_variacao', $variacoes)
            ->orderBy('id_variacao')
            ->orderBy('id_deposito')
            ->get(['id_variacao', 'id_deposito', 'quantidade'])
            ->map(fn (Estoque $saldo) => [
                'id_variacao' => (int) $saldo->id_variacao,
                'id_deposito' => (int) $saldo->id_deposito,
                'quantidade' => (int) $saldo->quantidade,
            ])
            ->all();
    }

    /** @param array<string,mixed> $resultado */
    private function registrarAuditoria(Pedido $pedido, array $resultado, string $chave): void
    {
        $this->auditoria->registrar(
            module: 'pedidos',
            action: 'pedido.tipo_conversao_reconciliada',
            label: "Conversão de reposição para venda reconciliada no Pedido #{$pedido->id}",
            auditable: $pedido,
            mudancas: [[
                'campo' => 'tipo',
                'old' => Pedido::TIPO_REPOSICAO,
                'new' => Pedido::TIPO_VENDA,
                'value_type' => 'string',
            ]],
            metadata: [
                ...$resultado,
                'idempotency_key' => $chave,
            ]
        );
    }
}
