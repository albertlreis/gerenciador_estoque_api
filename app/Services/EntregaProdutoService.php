<?php

namespace App\Services;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Models\AssistenciaChamadoItem;
use App\Models\Consignacao;
use App\Models\DevolucaoItem;
use App\Models\EstoqueReserva;
use App\Models\Pedido;
use App\Models\PedidoFabricaItem;
use App\Models\PedidoItem;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EntregaProdutoService
{
    public function __construct(
        private readonly EstoqueDisponibilidadeService $disponibilidade,
        private readonly ReservaEstoqueService $reservas,
        private readonly EstoqueMovimentacaoService $movimentacoes,
    ) {}

    public function criarDemandaPedido(Pedido $pedido, ?int $usuarioId = null, bool $reservarAutomaticamente = true): Collection
    {
        return DB::transaction(function () use ($pedido, $usuarioId, $reservarAutomaticamente) {
            $pedido->loadMissing('itens');

            return $pedido->itens->map(function (PedidoItem $pedidoItem) use ($pedido, $usuarioId, $reservarAutomaticamente) {
                $entrega = $this->criarOuAtualizarItemPedido($pedido, $pedidoItem, $usuarioId);

                if ($reservarAutomaticamente) {
                    $this->reservarItem($entrega, $pedidoItem->id_deposito, null, $usuarioId, 'Reserva automatica do pedido');
                }

                return $entrega->fresh(['eventos']);
            });
        });
    }

    public function reconciliarPedidoEditado(Pedido $pedido, ?int $usuarioId = null): Collection
    {
        return DB::transaction(function () use ($pedido, $usuarioId) {
            $pedido->loadMissing('itens');
            $idsAtuais = $pedido->itens->pluck('id')->all();

            ProdutoEntregaItem::query()
                ->where('pedido_id', $pedido->id)
                ->whereNotNull('pedido_item_id')
                ->whereNotIn('pedido_item_id', $idsAtuais)
                ->whereNotIn('status', [ProdutoEntregaItem::STATUS_CANCELADO, ProdutoEntregaItem::STATUS_ENTREGUE])
                ->get()
                ->each(fn (ProdutoEntregaItem $item) => $this->cancelarItem($item, $usuarioId, 'Item removido do pedido.'));

            return $this->criarDemandaPedido($pedido, $usuarioId, true);
        });
    }

    public function criarDemandaFabricaItem(PedidoFabricaItem $item, ?int $usuarioId = null): ProdutoEntregaItem
    {
        return DB::transaction(function () use ($item, $usuarioId) {
            $entrega = ProdutoEntregaItem::query()->updateOrCreate(
                ['pedido_fabrica_item_id' => $item->id],
                [
                    'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO_FABRICA,
                    'origem_id' => $item->pedido_fabrica_id,
                    'pedido_fabrica_item_id' => $item->id,
                    'pedido_id' => $item->pedido_venda_id,
                    'id_variacao' => $item->produto_variacao_id,
                    'quantidade_total' => (int) $item->quantidade,
                    'id_deposito_destino' => $item->deposito_id,
                    'status' => $this->statusRecebimento((int) $item->quantidade, (int) $item->quantidade_entregue),
                    'bloqueio_motivo' => null,
                ]
            );

            $this->registrarEvento(
                $entrega,
                ProdutoEntregaEvento::DEMANDA_CRIADA,
                (int) $item->quantidade,
                null,
                $item->deposito_id,
                null,
                null,
                $usuarioId,
                'Demanda de recebimento de fabrica criada.',
                ['pedido_fabrica_item_id' => $item->id],
                "fabrica-item:{$item->id}:demanda"
            );

            return $entrega->fresh(['eventos']);
        });
    }

    public function criarDemandaConsignacao(Consignacao $consignacao, ?int $usuarioId = null): ProdutoEntregaItem
    {
        return DB::transaction(function () use ($consignacao, $usuarioId) {
            $entrega = ProdutoEntregaItem::query()->updateOrCreate(
                [
                    'tipo_origem' => ProdutoEntregaItem::ORIGEM_CONSIGNACAO,
                    'consignacao_id' => $consignacao->id,
                ],
                [
                    'tipo_origem' => ProdutoEntregaItem::ORIGEM_CONSIGNACAO,
                    'origem_id' => $consignacao->id,
                    'pedido_id' => $consignacao->pedido_id,
                    'consignacao_id' => $consignacao->id,
                    'id_variacao' => $consignacao->produto_variacao_id,
                    'quantidade_total' => (int) $consignacao->quantidade,
                    'id_deposito_origem' => $consignacao->deposito_id,
                    'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
                ]
            );

            $this->registrarEvento(
                $entrega,
                ProdutoEntregaEvento::DEMANDA_CRIADA,
                (int) $consignacao->quantidade,
                $consignacao->deposito_id,
                null,
                null,
                null,
                $usuarioId,
                'Demanda de consignacao criada.',
                ['consignacao_id' => $consignacao->id],
                "consignacao:{$consignacao->id}:demanda"
            );

            return $entrega->fresh(['eventos']);
        });
    }

    public function criarDemandaAssistencia(AssistenciaChamadoItem $item, ?int $usuarioId = null): ProdutoEntregaItem
    {
        return DB::transaction(function () use ($item, $usuarioId) {
            $entrega = ProdutoEntregaItem::query()->updateOrCreate(
                ['assistencia_item_id' => $item->id],
                [
                    'tipo_origem' => ProdutoEntregaItem::ORIGEM_ASSISTENCIA,
                    'origem_id' => $item->chamado_id,
                    'pedido_item_id' => $item->pedido_item_id,
                    'consignacao_id' => $item->consignacao_id,
                    'assistencia_item_id' => $item->id,
                    'id_variacao' => $item->variacao_id,
                    'quantidade_total' => 1,
                    'id_deposito_origem' => $item->deposito_origem_id,
                    'id_deposito_destino' => $item->deposito_assistencia_id,
                    'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
                    'previsao_entrega' => $item->prazo_finalizacao,
                ]
            );

            $this->registrarEvento(
                $entrega,
                ProdutoEntregaEvento::DEMANDA_CRIADA,
                1,
                $item->deposito_origem_id,
                $item->deposito_assistencia_id,
                null,
                null,
                $usuarioId,
                'Demanda de assistencia criada.',
                ['assistencia_item_id' => $item->id],
                "assistencia-item:{$item->id}:demanda"
            );

            return $entrega->fresh(['eventos']);
        });
    }

    public function criarDemandaDevolucaoItem(DevolucaoItem $item, ?int $usuarioId = null): ProdutoEntregaItem
    {
        return DB::transaction(function () use ($item, $usuarioId) {
            $item->loadMissing('devolucao', 'pedidoItem');

            $entrega = ProdutoEntregaItem::query()->updateOrCreate(
                ['devolucao_item_id' => $item->id],
                [
                    'tipo_origem' => ProdutoEntregaItem::ORIGEM_DEVOLUCAO,
                    'origem_id' => $item->devolucao_id,
                    'pedido_id' => $item->devolucao?->pedido_id,
                    'pedido_item_id' => $item->pedido_item_id,
                    'devolucao_item_id' => $item->id,
                    'id_variacao' => $item->pedidoItem?->id_variacao,
                    'quantidade_total' => (int) $item->quantidade,
                    'id_deposito_destino' => $item->pedidoItem?->id_deposito,
                    'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
                ]
            );

            $this->registrarEvento(
                $entrega,
                ProdutoEntregaEvento::DEMANDA_CRIADA,
                (int) $item->quantidade,
                null,
                $item->pedidoItem?->id_deposito,
                null,
                null,
                $usuarioId,
                'Demanda de devolucao criada.',
                ['devolucao_item_id' => $item->id],
                "devolucao-item:{$item->id}:demanda"
            );

            return $entrega->fresh(['eventos']);
        });
    }

    public function reservarPedido(Pedido $pedido, ?int $usuarioId = null): Collection
    {
        return $this->criarDemandaPedido($pedido, $usuarioId, true);
    }

    public function expedirPedido(Pedido $pedido, ?int $usuarioId = null, string $tipoEvento = ProdutoEntregaEvento::EXPEDIDO_CLIENTE): Collection
    {
        return DB::transaction(function () use ($pedido, $usuarioId, $tipoEvento) {
            $itens = ProdutoEntregaItem::query()
                ->where('pedido_id', $pedido->id)
                ->whereNotIn('status', [ProdutoEntregaItem::STATUS_CANCELADO, ProdutoEntregaItem::STATUS_ENTREGUE])
                ->lockForUpdate()
                ->get();

            if ($itens->isEmpty()) {
                $itens = $this->criarDemandaPedido($pedido, $usuarioId, true);
            }

            return collect($itens)->map(fn (ProdutoEntregaItem $item) => $this->expedirItem($item, null, null, $usuarioId, null, $tipoEvento));
        });
    }

    public function entregarPedido(Pedido $pedido, ?int $usuarioId = null): Collection
    {
        return DB::transaction(function () use ($pedido, $usuarioId) {
            return ProdutoEntregaItem::query()
                ->where('pedido_id', $pedido->id)
                ->whereNotIn('status', [ProdutoEntregaItem::STATUS_CANCELADO, ProdutoEntregaItem::STATUS_ENTREGUE])
                ->lockForUpdate()
                ->get()
                ->map(fn (ProdutoEntregaItem $item) => $this->entregarItem($item, null, $usuarioId, 'Entrega do pedido ao cliente.'));
        });
    }

    public function reservarItem(
        ProdutoEntregaItem|int $item,
        ?int $depositoId = null,
        ?int $quantidade = null,
        ?int $usuarioId = null,
        ?string $observacao = null,
        ?string $idempotencyKey = null
    ): ProdutoEntregaItem {
        return DB::transaction(function () use ($item, $depositoId, $quantidade, $usuarioId, $observacao, $idempotencyKey) {
            $entrega = $this->lockItem($item);

            if (in_array($entrega->status, [ProdutoEntregaItem::STATUS_CANCELADO, ProdutoEntregaItem::STATUS_ENTREGUE], true)) {
                return $entrega;
            }

            $depositoId = $depositoId ?: $entrega->id_deposito_origem;
            if (!$depositoId) {
                $entrega->update([
                    'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
                    'bloqueio_motivo' => 'Deposito de origem nao definido para reserva.',
                ]);
                return $entrega->fresh();
            }

            $pendente = max(0, (int) $entrega->quantidade_total - (int) $entrega->quantidade_reservada - (int) $entrega->quantidade_expedida);
            $quantidade = $quantidade !== null ? min((int) $quantidade, $pendente) : $pendente;
            if ($quantidade <= 0) {
                return $entrega;
            }

            $disponivel = $this->disponibilidade->getDisponivel((int) $entrega->id_variacao, (int) $depositoId);
            if ($disponivel < $quantidade) {
                $entrega->update([
                    'id_deposito_origem' => $depositoId,
                    'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
                    'bloqueio_motivo' => "Estoque insuficiente para reserva. Disponivel: {$disponivel}, solicitado: {$quantidade}.",
                ]);
                return $entrega->fresh();
            }

            $key = $idempotencyKey ?: "entrega:{$entrega->id}:reserva:" . ((int) $entrega->quantidade_reservada) . ":{$quantidade}:{$depositoId}";
            if ($this->eventoJaRegistrado($key)) {
                return $entrega;
            }

            $reserva = $this->reservas->reservar(
                variacaoId: (int) $entrega->id_variacao,
                depositoId: (int) $depositoId,
                quantidade: (int) $quantidade,
                pedidoId: $entrega->pedido_id ? (int) $entrega->pedido_id : null,
                pedidoItemId: $entrega->pedido_item_id ? (int) $entrega->pedido_item_id : null,
                usuarioId: $usuarioId,
                motivo: 'produto_entrega'
            );

            $entrega->quantidade_reservada = (int) $entrega->quantidade_reservada + (int) $quantidade;
            $entrega->id_deposito_origem = $depositoId;
            $entrega->bloqueio_motivo = null;
            $entrega->status = $entrega->quantidade_reservada >= $entrega->quantidade_total
                ? ProdutoEntregaItem::STATUS_RESERVADO
                : ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE;
            $entrega->save();

            $this->registrarEvento(
                $entrega,
                ProdutoEntregaEvento::RESERVA_CRIADA,
                (int) $quantidade,
                (int) $depositoId,
                null,
                (int) $reserva->id,
                null,
                $usuarioId,
                $observacao ?: 'Reserva criada pelo fluxo central de entrega.',
                [],
                $key
            );

            return $entrega->fresh(['eventos']);
        });
    }

    public function receberItem(
        ProdutoEntregaItem|int $item,
        ?int $depositoId = null,
        ?int $quantidade = null,
        ?int $usuarioId = null,
        ?string $observacao = null,
        ?string $idempotencyKey = null,
        string $tipoEvento = ProdutoEntregaEvento::RECEBIDO_ESTOQUE,
        ?int $depositoOrigemId = null
    ): ProdutoEntregaItem {
        return DB::transaction(function () use ($item, $depositoId, $quantidade, $usuarioId, $observacao, $idempotencyKey, $tipoEvento, $depositoOrigemId) {
            $entrega = $this->lockItem($item);
            $depositoId = $depositoId ?: $entrega->id_deposito_destino ?: $entrega->id_deposito_origem;

            if (!$depositoId) {
                throw ValidationException::withMessages([
                    'deposito_id' => ['Informe o deposito de recebimento.'],
                ]);
            }

            $pendente = max(0, (int) $entrega->quantidade_total - (int) $entrega->quantidade_recebida);
            $quantidade = $quantidade !== null ? min((int) $quantidade, $pendente) : $pendente;
            if ($quantidade <= 0) {
                return $entrega;
            }

            $key = $idempotencyKey ?: "entrega:{$entrega->id}:receber:" . ((int) $entrega->quantidade_recebida) . ":{$quantidade}:{$depositoId}";
            if ($this->eventoJaRegistrado($key)) {
                return $entrega;
            }

            $tipoMovimentacao = match ($tipoEvento) {
                ProdutoEntregaEvento::RETORNADO_CONSIGNACAO => EstoqueMovimentacaoTipo::CONSIGNACAO_DEVOLUCAO->value,
                ProdutoEntregaEvento::RETORNADO_ASSISTENCIA => EstoqueMovimentacaoTipo::ASSISTENCIA_RETORNO->value,
                default => EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
            };

            $movimentacao = $this->movimentacoes->registrarMovimentacaoManual([
                'id_variacao' => (int) $entrega->id_variacao,
                'id_deposito_origem' => $depositoOrigemId,
                'id_deposito_destino' => (int) $depositoId,
                'tipo' => $tipoMovimentacao,
                'quantidade' => (int) $quantidade,
                'observacao' => $observacao ?: "Recebimento central do item de entrega #{$entrega->id}",
                'data_movimentacao' => now(),
                'ref_type' => $entrega->tipo_origem,
                'ref_id' => $entrega->origem_id,
                'pedido_id' => $entrega->pedido_id,
                'pedido_item_id' => $entrega->pedido_item_id,
            ], $usuarioId);

            $entrega->quantidade_recebida = (int) $entrega->quantidade_recebida + (int) $quantidade;
            $entrega->id_deposito_destino = $depositoId;
            $entrega->status = $this->statusRecebimento((int) $entrega->quantidade_total, (int) $entrega->quantidade_recebida);
            $entrega->bloqueio_motivo = null;
            $entrega->save();

            $this->registrarEvento(
                $entrega,
                $tipoEvento,
                (int) $quantidade,
                $depositoOrigemId,
                (int) $depositoId,
                null,
                (int) $movimentacao->id,
                $usuarioId,
                $observacao ?: 'Recebimento registrado pelo fluxo central de entrega.',
                [],
                $key
            );

            if ($tipoEvento === ProdutoEntregaEvento::RECEBIDO_ESTOQUE) {
                $this->reservarDemandasLiberadasPorRecebimento($entrega, (int) $depositoId, (int) $quantidade, $usuarioId);
            }

            return $entrega->fresh(['eventos']);
        });
    }

    public function expedirItem(
        ProdutoEntregaItem|int $item,
        ?int $depositoId = null,
        ?int $quantidade = null,
        ?int $usuarioId = null,
        ?string $observacao = null,
        string $tipoEvento = ProdutoEntregaEvento::EXPEDIDO_CLIENTE,
        ?string $idempotencyKey = null,
        ?int $depositoDestinoId = null
    ): ProdutoEntregaItem {
        return DB::transaction(function () use ($item, $depositoId, $quantidade, $usuarioId, $observacao, $tipoEvento, $idempotencyKey, $depositoDestinoId) {
            $entrega = $this->lockItem($item);

            if (in_array($entrega->status, [ProdutoEntregaItem::STATUS_CANCELADO, ProdutoEntregaItem::STATUS_ENTREGUE], true)) {
                return $entrega;
            }

            $depositoId = $depositoId ?: $entrega->id_deposito_origem;
            if (!$depositoId) {
                throw ValidationException::withMessages([
                    'deposito_id' => ['Informe o deposito de saida.'],
                ]);
            }

            $pendente = max(0, (int) $entrega->quantidade_total - (int) $entrega->quantidade_expedida);
            $quantidade = $quantidade !== null ? min((int) $quantidade, $pendente) : $pendente;
            if ($quantidade <= 0) {
                return $entrega;
            }

            if (
                $tipoEvento !== ProdutoEntregaEvento::ENVIADO_ASSISTENCIA
                && (int) $entrega->quantidade_reservada < (int) $entrega->quantidade_expedida + $quantidade
            ) {
                $this->reservarItem($entrega, (int) $depositoId, $quantidade, $usuarioId, 'Reserva imediata para expedicao.');
                $entrega = $this->lockItem($entrega->id);
            }

            $key = $idempotencyKey ?: "entrega:{$entrega->id}:{$tipoEvento}:" . ((int) $entrega->quantidade_expedida) . ":{$quantidade}:{$depositoId}";
            if ($this->eventoJaRegistrado($key)) {
                return $entrega;
            }

            $reservaIdParaConsumo = ProdutoEntregaEvento::query()
                ->where('produto_entrega_item_id', $entrega->id)
                ->where('tipo_evento', ProdutoEntregaEvento::RESERVA_CRIADA)
                ->whereNotNull('estoque_reserva_id')
                ->orderByDesc('id')
                ->value('estoque_reserva_id');

            if ($tipoEvento === ProdutoEntregaEvento::ENVIADO_ASSISTENCIA) {
                if (!$depositoDestinoId) {
                    throw ValidationException::withMessages([
                        'deposito_destino_id' => ['Informe o deposito de destino.'],
                    ]);
                }

                $movimentacao = $this->movimentacoes->registrarMovimentacaoManual([
                    'id_variacao' => (int) $entrega->id_variacao,
                    'id_deposito_origem' => (int) $depositoId,
                    'id_deposito_destino' => (int) $depositoDestinoId,
                    'tipo' => EstoqueMovimentacaoTipo::ASSISTENCIA_ENVIO->value,
                    'quantidade' => (int) $quantidade,
                    'observacao' => $observacao ?: "Envio central do item de entrega #{$entrega->id}",
                    'data_movimentacao' => now(),
                    'ref_type' => $entrega->tipo_origem,
                    'ref_id' => $entrega->origem_id,
                    'pedido_id' => $entrega->pedido_id,
                    'pedido_item_id' => $entrega->pedido_item_id,
                ], $usuarioId);
            } else {
                $tipoMovimentacao = $tipoEvento === ProdutoEntregaEvento::ENVIADO_CONSIGNACAO
                    ? EstoqueMovimentacaoTipo::CONSIGNACAO_ENVIO->value
                    : EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value;

                $movimentacao = $this->movimentacoes->registrarSaidaPedido(
                    variacaoId: (int) $entrega->id_variacao,
                    depositoSaidaId: (int) $depositoId,
                    quantidade: (int) $quantidade,
                    usuarioId: $usuarioId,
                    observacao: $observacao ?: "Expedicao central do item de entrega #{$entrega->id}",
                    pedidoId: $entrega->pedido_id ? (int) $entrega->pedido_id : null,
                    pedidoItemId: $entrega->pedido_item_id ? (int) $entrega->pedido_item_id : null,
                    reservaId: $reservaIdParaConsumo ? (int) $reservaIdParaConsumo : null,
                    tipoMovimentacao: $tipoMovimentacao,
                );
            }

            $entrega->quantidade_expedida = (int) $entrega->quantidade_expedida + (int) $quantidade;
            $entrega->id_deposito_origem = $depositoId;
            $entrega->status = $entrega->quantidade_expedida >= $entrega->quantidade_total
                ? ProdutoEntregaItem::STATUS_EXPEDIDO
                : ProdutoEntregaItem::STATUS_EXPEDIDO_PARCIAL;
            $entrega->bloqueio_motivo = null;
            $entrega->save();

            $this->registrarEvento(
                $entrega,
                $tipoEvento,
                (int) $quantidade,
                (int) $depositoId,
                $depositoDestinoId,
                $movimentacao?->reserva_id,
                $movimentacao?->id,
                $usuarioId,
                $observacao ?: 'Expedicao registrada pelo fluxo central de entrega.',
                [],
                $key
            );

            return $entrega->fresh(['eventos']);
        });
    }

    public function enviarAssistenciaItem(
        ProdutoEntregaItem|int $item,
        int $depositoAssistenciaId,
        ?int $usuarioId = null,
        ?string $observacao = null,
        ?string $idempotencyKey = null
    ): ProdutoEntregaItem {
        return DB::transaction(function () use ($item, $depositoAssistenciaId, $usuarioId, $observacao, $idempotencyKey) {
            $entrega = $this->lockItem($item);
            $depositoOrigemId = $entrega->id_deposito_origem;

            if (!$depositoOrigemId) {
                throw ValidationException::withMessages([
                    'deposito_origem_id' => ['Informe o deposito de origem.'],
                ]);
            }

            $key = $idempotencyKey ?: "entrega:{$entrega->id}:assistencia-envio:{$depositoOrigemId}:{$depositoAssistenciaId}";
            if ($this->eventoJaRegistrado($key)) {
                return $entrega;
            }

            $movimentacao = $this->movimentacoes->registrarMovimentacaoManual([
                'id_variacao' => (int) $entrega->id_variacao,
                'id_deposito_origem' => (int) $depositoOrigemId,
                'id_deposito_destino' => (int) $depositoAssistenciaId,
                'tipo' => EstoqueMovimentacaoTipo::ASSISTENCIA_ENVIO->value,
                'quantidade' => 1,
                'observacao' => $observacao ?: "Envio de assistencia do item de entrega #{$entrega->id}",
                'data_movimentacao' => now(),
                'ref_type' => $entrega->tipo_origem,
                'ref_id' => $entrega->origem_id,
                'pedido_id' => $entrega->pedido_id,
                'pedido_item_id' => $entrega->pedido_item_id,
            ], $usuarioId);

            $entrega->id_deposito_destino = $depositoAssistenciaId;
            $entrega->status = ProdutoEntregaItem::STATUS_PRONTO_EXPEDICAO;
            $entrega->bloqueio_motivo = null;
            $entrega->save();

            $this->registrarEvento(
                $entrega,
                ProdutoEntregaEvento::ENVIADO_ASSISTENCIA,
                1,
                (int) $depositoOrigemId,
                (int) $depositoAssistenciaId,
                null,
                (int) $movimentacao->id,
                $usuarioId,
                $observacao ?: 'Envio para assistencia registrado pelo fluxo central.',
                [],
                $key
            );

            return $entrega->fresh(['eventos']);
        });
    }

    public function entregarItem(
        ProdutoEntregaItem|int $item,
        ?int $quantidade = null,
        ?int $usuarioId = null,
        ?string $observacao = null,
        ?string $idempotencyKey = null
    ): ProdutoEntregaItem {
        return DB::transaction(function () use ($item, $quantidade, $usuarioId, $observacao, $idempotencyKey) {
            $entrega = $this->lockItem($item);

            if ($entrega->status === ProdutoEntregaItem::STATUS_CANCELADO) {
                return $entrega;
            }

            $baseEntregavel = (int) $entrega->quantidade_expedida;
            $pendente = max(0, $baseEntregavel - (int) $entrega->quantidade_entregue);
            $quantidade = $quantidade !== null ? min((int) $quantidade, $pendente) : $pendente;
            if ($quantidade <= 0) {
                return $entrega;
            }

            $key = $idempotencyKey ?: "entrega:{$entrega->id}:entregar:" . ((int) $entrega->quantidade_entregue) . ":{$quantidade}";
            if ($this->eventoJaRegistrado($key)) {
                return $entrega;
            }

            $entrega->quantidade_entregue = (int) $entrega->quantidade_entregue + (int) $quantidade;
            $entrega->status = $entrega->quantidade_entregue >= $entrega->quantidade_total
                ? ProdutoEntregaItem::STATUS_ENTREGUE
                : ProdutoEntregaItem::STATUS_ENTREGUE_PARCIAL;
            $entrega->bloqueio_motivo = null;
            $entrega->save();

            $this->registrarEvento(
                $entrega,
                ProdutoEntregaEvento::ENTREGUE_CLIENTE,
                (int) $quantidade,
                null,
                null,
                null,
                null,
                $usuarioId,
                $observacao ?: 'Entrega ao cliente registrada pelo fluxo central.',
                [],
                $key
            );

            return $entrega->fresh(['eventos']);
        });
    }

    public function cancelarItem(
        ProdutoEntregaItem|int $item,
        ?int $usuarioId = null,
        ?string $observacao = null,
        ?string $idempotencyKey = null
    ): ProdutoEntregaItem {
        return DB::transaction(function () use ($item, $usuarioId, $observacao, $idempotencyKey) {
            $entrega = $this->lockItem($item);

            if ($entrega->status === ProdutoEntregaItem::STATUS_CANCELADO) {
                return $entrega;
            }

            if ($entrega->pedido_item_id) {
                $this->reservas->cancelarPorPedidoItem((int) $entrega->pedido_item_id, $usuarioId, 'produto_entrega_cancelada');
            } elseif ($entrega->pedido_id && $entrega->tipo_origem === ProdutoEntregaItem::ORIGEM_PEDIDO) {
                $this->reservas->cancelarPorPedido((int) $entrega->pedido_id, $usuarioId, 'produto_entrega_cancelada');
            }

            $entrega->status = ProdutoEntregaItem::STATUS_CANCELADO;
            $entrega->quantidade_reservada = 0;
            $entrega->bloqueio_motivo = $observacao;
            $entrega->save();

            $this->registrarEvento(
                $entrega,
                ProdutoEntregaEvento::CANCELADO,
                0,
                $entrega->id_deposito_origem,
                $entrega->id_deposito_destino,
                null,
                null,
                $usuarioId,
                $observacao ?: 'Item de entrega cancelado.',
                [],
                $idempotencyKey ?: "entrega:{$entrega->id}:cancelado"
            );

            return $entrega->fresh(['eventos']);
        });
    }

    public function estornarEvento(ProdutoEntregaEvento|int $evento, ?int $usuarioId = null, ?string $observacao = null): ProdutoEntregaEvento
    {
        return DB::transaction(function () use ($evento, $usuarioId, $observacao) {
            $evento = $evento instanceof ProdutoEntregaEvento
                ? $evento
                : ProdutoEntregaEvento::query()->lockForUpdate()->findOrFail($evento);

            if ($evento->tipo_evento === ProdutoEntregaEvento::ESTORNADO) {
                return $evento;
            }

            $key = "evento:{$evento->id}:estorno";
            $existente = ProdutoEntregaEvento::query()->where('idempotency_key', $key)->first();
            if ($existente) {
                return $existente;
            }

            $movimentacaoEstorno = null;
            if ($evento->estoque_movimentacao_id) {
                $movimentacaoEstorno = $this->movimentacoes->estornarMovimentacao(
                    (int) $evento->estoque_movimentacao_id,
                    $usuarioId,
                    $observacao ?: "Estorno do evento central #{$evento->id}"
                );
            }

            if ($evento->estoque_reserva_id) {
                EstoqueReserva::query()
                    ->where('id', $evento->estoque_reserva_id)
                    ->where('status', 'ativa')
                    ->update([
                        'status' => 'cancelada',
                        'motivo' => 'estorno_produto_entrega',
                        'updated_at' => now(),
                    ]);
            }

            $item = ProdutoEntregaItem::query()
                ->lockForUpdate()
                ->findOrFail($evento->produto_entrega_item_id);

            $this->aplicarEstornoNoItem($item, $evento);

            return $this->registrarEvento(
                $item,
                ProdutoEntregaEvento::ESTORNADO,
                (int) $evento->quantidade,
                $evento->id_deposito_destino,
                $evento->id_deposito_origem,
                $evento->estoque_reserva_id,
                $movimentacaoEstorno?->id,
                $usuarioId,
                $observacao ?: "Evento #{$evento->id} estornado.",
                ['evento_original_id' => $evento->id],
                $key
            );
        });
    }

    public function resumoPedido(Pedido $pedido): array
    {
        $itens = $pedido->relationLoaded('entregaItens')
            ? $pedido->entregaItens
            : $pedido->entregaItens()->get();

        return $this->resumirItens($itens);
    }

    public function resumirItens(Collection|EloquentCollection $itens): array
    {
        $total = (int) $itens->sum('quantidade_total');

        return [
            'total_itens' => $itens->count(),
            'quantidade_total' => $total,
            'quantidade_reservada' => (int) $itens->sum('quantidade_reservada'),
            'quantidade_recebida' => (int) $itens->sum('quantidade_recebida'),
            'quantidade_expedida' => (int) $itens->sum('quantidade_expedida'),
            'quantidade_entregue' => (int) $itens->sum('quantidade_entregue'),
            'pendentes_revisao' => $itens->where('status', ProdutoEntregaItem::STATUS_BLOQUEADO_REVISAO)->count(),
            'status' => $this->statusAgregado($itens, $total),
        ];
    }

    private function criarOuAtualizarItemPedido(Pedido $pedido, PedidoItem $pedidoItem, ?int $usuarioId = null): ProdutoEntregaItem
    {
        $status = $pedidoItem->id_deposito
            ? ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE
            : ProdutoEntregaItem::STATUS_BLOQUEADO_REVISAO;

        $bloqueio = $pedidoItem->id_deposito ? null : 'Pedido item sem deposito de origem.';

        $entrega = ProdutoEntregaItem::query()->firstOrNew([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
            'pedido_item_id' => $pedidoItem->id,
        ]);
        $statusAtual = (string) ($entrega->status ?? '');
        $mantemStatus = in_array($statusAtual, [
            ProdutoEntregaItem::STATUS_EXPEDIDO,
            ProdutoEntregaItem::STATUS_EXPEDIDO_PARCIAL,
            ProdutoEntregaItem::STATUS_ENTREGUE,
            ProdutoEntregaItem::STATUS_ENTREGUE_PARCIAL,
            ProdutoEntregaItem::STATUS_CANCELADO,
        ], true);

        $entrega->fill([
                'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
                'origem_id' => $pedido->id,
                'pedido_id' => $pedido->id,
                'pedido_item_id' => $pedidoItem->id,
                'id_variacao' => $pedidoItem->id_variacao,
                'quantidade_total' => (int) $pedidoItem->quantidade,
                'id_deposito_origem' => $pedidoItem->id_deposito,
                'previsao_entrega' => $pedido->data_limite_entrega,
                'status' => $mantemStatus ? $statusAtual : $status,
                'bloqueio_motivo' => $bloqueio,
        ]);
        $entrega->save();

        $this->registrarEvento(
            $entrega,
            ProdutoEntregaEvento::DEMANDA_CRIADA,
            (int) $pedidoItem->quantidade,
            $pedidoItem->id_deposito,
            null,
            null,
            null,
            $usuarioId,
            'Demanda de pedido criada.',
            ['pedido_item_id' => $pedidoItem->id],
            "pedido-item:{$pedidoItem->id}:demanda"
        );

        return $entrega->fresh(['eventos']);
    }

    private function registrarEvento(
        ProdutoEntregaItem $item,
        string $tipo,
        int $quantidade,
        ?int $depositoOrigem,
        ?int $depositoDestino,
        ?int $reservaId,
        ?int $movimentacaoId,
        ?int $usuarioId,
        ?string $observacao,
        array $metadata,
        string $idempotencyKey
    ): ProdutoEntregaEvento {
        return ProdutoEntregaEvento::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'produto_entrega_item_id' => $item->id,
                'tipo_evento' => $tipo,
                'quantidade' => max(0, $quantidade),
                'id_deposito_origem' => $depositoOrigem,
                'id_deposito_destino' => $depositoDestino,
                'estoque_reserva_id' => $reservaId,
                'estoque_movimentacao_id' => $movimentacaoId,
                'usuario_id' => $usuarioId,
                'observacao' => $observacao,
                'metadata_json' => $metadata === [] ? null : $metadata,
            ]
        );
    }

    private function lockItem(ProdutoEntregaItem|int $item): ProdutoEntregaItem
    {
        $id = $item instanceof ProdutoEntregaItem ? $item->id : $item;

        return ProdutoEntregaItem::query()->lockForUpdate()->findOrFail($id);
    }

    private function eventoJaRegistrado(string $idempotencyKey): bool
    {
        return ProdutoEntregaEvento::query()->where('idempotency_key', $idempotencyKey)->exists();
    }

    private function statusRecebimento(int $total, int $recebido): string
    {
        if ($total > 0 && $recebido >= $total) {
            return ProdutoEntregaItem::STATUS_RECEBIDO;
        }

        if ($recebido > 0) {
            return ProdutoEntregaItem::STATUS_RECEBIDO_PARCIAL;
        }

        return ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE;
    }

    private function aplicarEstornoNoItem(ProdutoEntregaItem $item, ProdutoEntregaEvento $evento): void
    {
        $quantidade = (int) $evento->quantidade;

        match ($evento->tipo_evento) {
            ProdutoEntregaEvento::RESERVA_CRIADA => $item->quantidade_reservada = max(0, (int) $item->quantidade_reservada - $quantidade),
            ProdutoEntregaEvento::RECEBIDO_ESTOQUE,
            ProdutoEntregaEvento::RETORNADO_CONSIGNACAO,
            ProdutoEntregaEvento::RETORNADO_ASSISTENCIA,
            ProdutoEntregaEvento::DEVOLUCAO_RECEBIDA => $item->quantidade_recebida = max(0, (int) $item->quantidade_recebida - $quantidade),
            ProdutoEntregaEvento::EXPEDIDO_CLIENTE,
            ProdutoEntregaEvento::ENVIADO_CONSIGNACAO => $item->quantidade_expedida = max(0, (int) $item->quantidade_expedida - $quantidade),
            ProdutoEntregaEvento::ENTREGUE_CLIENTE => $item->quantidade_entregue = max(0, (int) $item->quantidade_entregue - $quantidade),
            default => null,
        };

        $item->status = $this->statusOperacional($item);
        $item->save();
    }

    private function statusOperacional(ProdutoEntregaItem $item): string
    {
        $total = (int) $item->quantidade_total;

        if ($total > 0 && (int) $item->quantidade_entregue >= $total) {
            return ProdutoEntregaItem::STATUS_ENTREGUE;
        }

        if ((int) $item->quantidade_entregue > 0) {
            return ProdutoEntregaItem::STATUS_ENTREGUE_PARCIAL;
        }

        if ($total > 0 && (int) $item->quantidade_expedida >= $total) {
            return ProdutoEntregaItem::STATUS_EXPEDIDO;
        }

        if ((int) $item->quantidade_expedida > 0) {
            return ProdutoEntregaItem::STATUS_EXPEDIDO_PARCIAL;
        }

        if ($total > 0 && (int) $item->quantidade_recebida >= $total) {
            return ProdutoEntregaItem::STATUS_RECEBIDO;
        }

        if ((int) $item->quantidade_recebida > 0) {
            return ProdutoEntregaItem::STATUS_RECEBIDO_PARCIAL;
        }

        if ($total > 0 && (int) $item->quantidade_reservada >= $total) {
            return ProdutoEntregaItem::STATUS_RESERVADO;
        }

        return ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE;
    }

    private function reservarDemandasLiberadasPorRecebimento(ProdutoEntregaItem $recebimento, int $depositoId, int $quantidadeRecebida, ?int $usuarioId): void
    {
        if (!$recebimento->pedido_id || $quantidadeRecebida <= 0) {
            return;
        }

        $restante = $quantidadeRecebida;
        ProdutoEntregaItem::query()
            ->where('pedido_id', $recebimento->pedido_id)
            ->where('id_variacao', $recebimento->id_variacao)
            ->whereIn('status', [
                ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
                ProdutoEntregaItem::STATUS_BLOQUEADO_REVISAO,
            ])
            ->orderBy('id')
            ->get()
            ->each(function (ProdutoEntregaItem $demanda) use (&$restante, $depositoId, $usuarioId) {
                if ($restante <= 0) {
                    return;
                }

                $pendente = max(0, (int) $demanda->quantidade_total - (int) $demanda->quantidade_reservada);
                if ($pendente <= 0) {
                    return;
                }

                $reservar = min($restante, $pendente);
                $this->reservarItem(
                    $demanda,
                    $depositoId,
                    $reservar,
                    $usuarioId,
                    'Reserva criada apos recebimento de fabrica.',
                    'recebimento:' . Str::uuid()
                );
                $restante -= $reservar;
            });
    }

    private function statusAgregado(Collection|EloquentCollection $itens, int $total): ?string
    {
        if ($itens->isEmpty()) {
            return null;
        }

        if ($itens->every(fn (ProdutoEntregaItem $item) => $item->status === ProdutoEntregaItem::STATUS_CANCELADO)) {
            return ProdutoEntregaItem::STATUS_CANCELADO;
        }

        if ($total > 0 && (int) $itens->sum('quantidade_entregue') >= $total) {
            return ProdutoEntregaItem::STATUS_ENTREGUE;
        }

        if ((int) $itens->sum('quantidade_entregue') > 0) {
            return ProdutoEntregaItem::STATUS_ENTREGUE_PARCIAL;
        }

        if ((int) $itens->sum('quantidade_expedida') > 0) {
            return (int) $itens->sum('quantidade_expedida') >= $total
                ? ProdutoEntregaItem::STATUS_EXPEDIDO
                : ProdutoEntregaItem::STATUS_EXPEDIDO_PARCIAL;
        }

        if ((int) $itens->sum('quantidade_reservada') > 0) {
            return (int) $itens->sum('quantidade_reservada') >= $total
                ? ProdutoEntregaItem::STATUS_RESERVADO
                : ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE;
        }

        if ($itens->contains(fn (ProdutoEntregaItem $item) => $item->status === ProdutoEntregaItem::STATUS_BLOQUEADO_REVISAO)) {
            return ProdutoEntregaItem::STATUS_BLOQUEADO_REVISAO;
        }

        return ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE;
    }
}
