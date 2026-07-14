<?php

namespace App\Services;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\PedidoStatus;
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
use InvalidArgumentException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntregaProdutoService
{
    private const OPERACAO_ANTECIPACAO = 'antecipacao_estoque_atual';

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

                if ($reservarAutomaticamente && $pedido->isVenda()) {
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

            $reservarAutomaticamente = ! (
                $pedido->isVenda()
                && $pedido->origem_abastecimento === Pedido::ORIGEM_ABASTECIMENTO_FABRICA
            );

            return $this->criarDemandaPedido($pedido, $usuarioId, $reservarAutomaticamente);
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
                    'em_revisao' => ! $item->deposito_id,
                    'bloqueio_motivo' => $item->deposito_id ? null : 'Pedido de fabrica sem deposito de destino.',
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
                    'pedido_item_id' => $consignacao->pedido_item_id,
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

    public function receberPedido(Pedido $pedido, ?int $usuarioId = null, ?string $observacao = null): Collection
    {
        return DB::transaction(function () use ($pedido, $usuarioId, $observacao) {
            $itens = ProdutoEntregaItem::query()
                ->where('pedido_id', $pedido->id)
                ->whereColumn('quantidade_recebida', '<', 'quantidade_total')
                ->whereNotIn('status', [ProdutoEntregaItem::STATUS_CANCELADO, ProdutoEntregaItem::STATUS_RECEBIDO])
                ->lockForUpdate()
                ->get();

            if ($itens->isEmpty()) {
                $itens = $this->criarDemandaPedido($pedido, $usuarioId, false)
                    ->filter(fn (ProdutoEntregaItem $item) => (int) $item->quantidade_recebida < (int) $item->quantidade_total);
            }

            return collect($itens)->map(fn (ProdutoEntregaItem $item) => $this->receberItem(
                $item,
                $item->id_deposito_destino ?: $item->id_deposito_origem,
                null,
                $usuarioId,
                $observacao ?: 'Recebimento do pedido ao estoque.',
                "pedido:{$pedido->id}:receber-item:{$item->id}"
            ));
        });
    }

    public function registrarAntecipacao(
        Pedido $pedido,
        PedidoItem $pedidoItem,
        int $depositoId,
        int $quantidade,
        string $idempotencyKey,
        ?int $usuarioId = null,
        ?string $observacao = null
    ): ProdutoEntregaItem {
        return DB::transaction(function () use ($pedido, $pedidoItem, $depositoId, $quantidade, $idempotencyKey, $usuarioId, $observacao) {
            $this->validarContextoAntecipacao($pedido, $pedidoItem);

            $entrega = ProdutoEntregaItem::query()
                ->where('pedido_id', $pedido->id)
                ->where('pedido_item_id', $pedidoItem->id)
                ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
                ->lockForUpdate()
                ->first();

            if (! $entrega) {
                $entrega = $this->criarOuAtualizarItemPedido($pedido, $pedidoItem, $usuarioId);
                $entrega = $this->lockItem($entrega->id);
            }

            $eventoExistente = ProdutoEntregaEvento::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($eventoExistente) {
                $metadata = (array) ($eventoExistente->metadata_json ?? []);
                $mesmaOperacao = (int) $eventoExistente->produto_entrega_item_id === (int) $entrega->id
                    && $eventoExistente->tipo_evento === ProdutoEntregaEvento::RESERVA_CRIADA
                    && ($metadata['operacao'] ?? null) === self::OPERACAO_ANTECIPACAO
                    && ($metadata['acao'] ?? null) === 'registrar'
                    && (int) ($metadata['deposito_id'] ?? 0) === $depositoId
                    && (int) ($metadata['quantidade'] ?? 0) === $quantidade;

                if (! $mesmaOperacao) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['Chave de idempotencia ja utilizada em outra operacao ou com outro payload.'],
                    ]);
                }

                return $entrega->fresh($this->relacoesEstadoAntecipacao());
            }

            if (in_array($entrega->status, [ProdutoEntregaItem::STATUS_CANCELADO, ProdutoEntregaItem::STATUS_ENTREGUE], true)) {
                throw ValidationException::withMessages([
                    'item' => ['Item cancelado ou ja entregue nao pode ser antecipado.'],
                ]);
            }

            $atendido = max(
                (int) $entrega->quantidade_reservada,
                (int) $entrega->quantidade_expedida,
                (int) $entrega->quantidade_entregue
            );
            $pendente = max(0, (int) $entrega->quantidade_total - $atendido);

            if ($quantidade > $pendente) {
                throw ValidationException::withMessages([
                    'quantidade' => ["Quantidade excede o pendente que ainda pode ser antecipado ({$pendente})."],
                ]);
            }

            $disponivel = $this->disponibilidade->getDisponivel((int) $entrega->id_variacao, $depositoId);
            if ($disponivel < $quantidade) {
                throw ValidationException::withMessages([
                    'quantidade' => ["Estoque liquido insuficiente para antecipacao. Disponivel: {$disponivel}, solicitado: {$quantidade}."],
                ]);
            }

            $entrega = $this->reservarItem(
                $entrega,
                $depositoId,
                $quantidade,
                $usuarioId,
                $observacao ?: 'Atendimento antecipado com estoque atual.',
                $idempotencyKey,
                [
                    'operacao' => self::OPERACAO_ANTECIPACAO,
                    'acao' => 'registrar',
                    'pedido_id' => (int) $pedido->id,
                    'pedido_item_id' => (int) $pedidoItem->id,
                    'deposito_id' => $depositoId,
                    'quantidade' => $quantidade,
                ],
                self::OPERACAO_ANTECIPACAO
            );

            if (! ProdutoEntregaEvento::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                throw ValidationException::withMessages([
                    'quantidade' => [$entrega->bloqueio_motivo ?: 'Nao foi possivel reservar o estoque para antecipacao.'],
                ]);
            }

            return $entrega->fresh($this->relacoesEstadoAntecipacao());
        });
    }

    public function cancelarAntecipacao(
        Pedido $pedido,
        PedidoItem $pedidoItem,
        string $idempotencyKey,
        ?int $usuarioId = null,
        ?string $observacao = null
    ): ProdutoEntregaItem {
        return DB::transaction(function () use ($pedido, $pedidoItem, $idempotencyKey, $usuarioId, $observacao) {
            $this->validarContextoAntecipacao($pedido, $pedidoItem);

            $entrega = ProdutoEntregaItem::query()
                ->where('pedido_id', $pedido->id)
                ->where('pedido_item_id', $pedidoItem->id)
                ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
                ->lockForUpdate()
                ->first();

            if (! $entrega) {
                throw ValidationException::withMessages([
                    'item' => ['Item ainda nao possui demanda operacional para cancelamento da antecipacao.'],
                ]);
            }

            $eventoExistente = ProdutoEntregaEvento::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($eventoExistente) {
                $metadata = (array) ($eventoExistente->metadata_json ?? []);
                $mesmaOperacao = (int) $eventoExistente->produto_entrega_item_id === (int) $entrega->id
                    && $eventoExistente->tipo_evento === ProdutoEntregaEvento::RESERVA_CANCELADA
                    && ($metadata['operacao'] ?? null) === self::OPERACAO_ANTECIPACAO
                    && ($metadata['acao'] ?? null) === 'cancelar';

                if (! $mesmaOperacao) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['Chave de idempotencia ja utilizada em outra operacao.'],
                    ]);
                }

                return $entrega->fresh($this->relacoesEstadoAntecipacao());
            }

            $eventosReserva = ProdutoEntregaEvento::query()
                ->with('reserva')
                ->where('produto_entrega_item_id', $entrega->id)
                ->where('tipo_evento', ProdutoEntregaEvento::RESERVA_CRIADA)
                ->orderBy('id')
                ->get()
                ->filter(fn (ProdutoEntregaEvento $evento) => $this->eventoEhAntecipacao($evento));
            $reservaIds = $eventosReserva->pluck('estoque_reserva_id')->filter()->unique()->values();
            $reservas = EstoqueReserva::query()
                ->whereIn('id', $reservaIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $cancelaveis = $eventosReserva->filter(function (ProdutoEntregaEvento $evento) use ($reservas) {
                $reserva = $reservas->get((int) $evento->estoque_reserva_id);

                return $reserva
                    && $reserva->status === 'ativa'
                    && (int) $reserva->quantidade_consumida === 0
                    && (int) $reserva->quantidade > 0;
            })->values();

            if ($cancelaveis->isEmpty()) {
                $mensagem = $eventosReserva->isEmpty()
                    ? 'Nao ha reserva antecipada ativa para este item.'
                    : 'A reserva antecipada ja foi consumida ou cancelada e nao pode ser cancelada novamente.';
                throw ValidationException::withMessages(['item' => [$mensagem]]);
            }

            $quantidadeCancelada = 0;
            foreach ($cancelaveis as $indice => $eventoReserva) {
                /** @var EstoqueReserva $reserva */
                $reserva = $reservas->get((int) $eventoReserva->estoque_reserva_id);
                $quantidadeReserva = (int) $reserva->quantidade;
                $reserva->update([
                    'status' => 'cancelada',
                    'motivo' => 'antecipacao_cancelada',
                ]);
                $quantidadeCancelada += $quantidadeReserva;
                $chaveEvento = $indice === 0
                    ? $idempotencyKey
                    : 'antecipacao-cancelar:' . hash('sha256', $idempotencyKey) . ':reserva:' . $reserva->id;

                $this->registrarEvento(
                    $entrega,
                    ProdutoEntregaEvento::RESERVA_CANCELADA,
                    $quantidadeReserva,
                    (int) $reserva->id_deposito,
                    null,
                    (int) $reserva->id,
                    null,
                    $usuarioId,
                    $observacao ?: 'Reserva de atendimento antecipado cancelada.',
                    [
                        'operacao' => self::OPERACAO_ANTECIPACAO,
                        'acao' => 'cancelar',
                        'pedido_id' => (int) $pedido->id,
                        'pedido_item_id' => (int) $pedidoItem->id,
                        'evento_reserva_id' => (int) $eventoReserva->id,
                        'idempotency_key_requisicao' => $idempotencyKey,
                    ],
                    $chaveEvento
                );
            }

            $entrega->quantidade_reservada = max(0, (int) $entrega->quantidade_reservada - $quantidadeCancelada);
            if (
                (int) $entrega->quantidade_reservada === 0
                && (int) $entrega->quantidade_expedida === 0
                && (int) $entrega->quantidade_entregue === 0
            ) {
                $entrega->id_deposito_origem = null;
            }
            $entrega->bloqueio_motivo = null;
            $entrega->em_revisao = false;
            $entrega->status = $this->statusOperacional($entrega);
            $entrega->save();

            return $entrega->fresh($this->relacoesEstadoAntecipacao());
        });
    }

    public function reservarItem(
        ProdutoEntregaItem|int $item,
        ?int $depositoId = null,
        ?int $quantidade = null,
        ?int $usuarioId = null,
        ?string $observacao = null,
        ?string $idempotencyKey = null,
        array $metadata = [],
        ?string $motivoReserva = null
    ): ProdutoEntregaItem {
        return DB::transaction(function () use ($item, $depositoId, $quantidade, $usuarioId, $observacao, $idempotencyKey, $metadata, $motivoReserva) {
            $entrega = $this->lockItem($item);

            if (in_array($entrega->status, [ProdutoEntregaItem::STATUS_CANCELADO, ProdutoEntregaItem::STATUS_ENTREGUE], true)) {
                return $entrega;
            }

            $depositoId = $depositoId ?: $entrega->id_deposito_origem;
            if (!$depositoId) {
                $entrega->update([
                    'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
                    'em_revisao' => true,
                    'bloqueio_motivo' => 'Deposito de origem nao definido para reserva.',
                ]);
                return $entrega->fresh();
            }

            $atendido = max(
                (int) $entrega->quantidade_reservada,
                (int) $entrega->quantidade_expedida,
                (int) $entrega->quantidade_entregue
            );
            $pendente = max(0, (int) $entrega->quantidade_total - $atendido);
            $quantidade = $quantidade !== null ? min((int) $quantidade, $pendente) : $pendente;
            if ($quantidade <= 0) {
                return $entrega;
            }

            $disponivel = $this->disponibilidade->getDisponivel((int) $entrega->id_variacao, (int) $depositoId);
            if ($disponivel < $quantidade) {
                $entrega->update([
                    'id_deposito_origem' => $depositoId,
                    'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
                    'em_revisao' => false,
                    'bloqueio_motivo' => "Estoque insuficiente para reserva. Disponivel: {$disponivel}, solicitado: {$quantidade}.",
                ]);
                return $entrega->fresh();
            }

            $key = $idempotencyKey ?: "entrega:{$entrega->id}:reserva:" . ((int) $entrega->quantidade_reservada) . ":{$quantidade}:{$depositoId}";
            if ($this->eventoJaRegistrado($key)) {
                return $entrega;
            }

            try {
                $reserva = $this->reservas->reservar(
                    variacaoId: (int) $entrega->id_variacao,
                    depositoId: (int) $depositoId,
                    quantidade: (int) $quantidade,
                    pedidoId: $entrega->pedido_id ? (int) $entrega->pedido_id : null,
                    pedidoItemId: $entrega->pedido_item_id ? (int) $entrega->pedido_item_id : null,
                    usuarioId: $usuarioId,
                    motivo: $motivoReserva ?: 'produto_entrega'
                );
            } catch (InvalidArgumentException $e) {
                $entrega->update([
                    'id_deposito_origem' => $depositoId,
                    'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
                    'em_revisao' => false,
                    'bloqueio_motivo' => $e->getMessage(),
                ]);
                return $entrega->fresh();
            }

            $entrega->quantidade_reservada = (int) $entrega->quantidade_reservada + (int) $quantidade;
            $entrega->id_deposito_origem = $depositoId;
            $entrega->bloqueio_motivo = null;
            $entrega->em_revisao = false;
            $entrega->status = $this->statusOperacional($entrega);
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
                $metadata,
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
        ?int $depositoOrigemId = null,
        mixed $ocorridoEm = null,
        bool $rejeitarExcesso = false
    ): ProdutoEntregaItem {
        return DB::transaction(function () use ($item, $depositoId, $quantidade, $usuarioId, $observacao, $idempotencyKey, $tipoEvento, $depositoOrigemId, $ocorridoEm, $rejeitarExcesso) {
            $entrega = $this->lockItem($item);
            $depositoId = $depositoId ?: $entrega->id_deposito_destino ?: $entrega->id_deposito_origem;

            if (!$depositoId) {
                throw ValidationException::withMessages([
                    'deposito_id' => ['Informe o deposito de recebimento.'],
                ]);
            }

            if ($idempotencyKey) {
                $eventoExistente = ProdutoEntregaEvento::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($eventoExistente) {
                    if (
                        (int) $eventoExistente->produto_entrega_item_id === (int) $entrega->id
                        && $eventoExistente->tipo_evento === $tipoEvento
                    ) {
                        return $entrega;
                    }

                    throw ValidationException::withMessages([
                        'idempotency_key' => ['Chave de idempotencia ja utilizada em outra operacao.'],
                    ]);
                }
            }

            $pendente = max(0, (int) $entrega->quantidade_total - (int) $entrega->quantidade_recebida);
            if ($rejeitarExcesso && $quantidade !== null && (int) $quantidade > $pendente) {
                throw ValidationException::withMessages([
                    'quantidade' => ["Quantidade excede o pendente de recebimento ({$pendente})."],
                ]);
            }

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
                'data_movimentacao' => $ocorridoEm ?: now(),
                'ref_type' => $entrega->tipo_origem,
                'ref_id' => $entrega->origem_id,
                'pedido_id' => $entrega->pedido_id,
                'pedido_item_id' => $entrega->pedido_item_id,
            ], $usuarioId);

            $entrega->quantidade_recebida = (int) $entrega->quantidade_recebida + (int) $quantidade;
            $entrega->id_deposito_destino = $depositoId;
            $entrega->status = $entrega->tipo_origem === ProdutoEntregaItem::ORIGEM_PEDIDO
                ? $this->statusOperacional($entrega)
                : $this->statusRecebimento((int) $entrega->quantidade_total, (int) $entrega->quantidade_recebida);
            $entrega->bloqueio_motivo = null;
            $entrega->em_revisao = false;
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
                $key,
                $ocorridoEm
            );

            if ($tipoEvento === ProdutoEntregaEvento::RECEBIDO_ESTOQUE) {
                $this->reservarDemandasLiberadasPorRecebimento($entrega, (int) $depositoId, (int) $quantidade, $usuarioId);
                $this->finalizarReposicaoSeRecebidaIntegralmente($entrega, $usuarioId);
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
        ?int $depositoDestinoId = null,
        mixed $ocorridoEm = null,
        array $metadata = []
    ): ProdutoEntregaItem {
        return DB::transaction(function () use ($item, $depositoId, $quantidade, $usuarioId, $observacao, $tipoEvento, $idempotencyKey, $depositoDestinoId, $ocorridoEm, $metadata) {
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
                ->where('id_deposito_origem', (int) $depositoId)
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
                    'data_movimentacao' => $ocorridoEm ?: now(),
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
                    refType: $entrega->tipo_origem,
                    refId: $entrega->origem_id ? (int) $entrega->origem_id : null,
                    dataMovimentacao: $ocorridoEm,
                );
            }

            $entrega->quantidade_expedida = (int) $entrega->quantidade_expedida + (int) $quantidade;
            $entrega->id_deposito_origem = $depositoId;
            $entrega->status = $this->statusOperacional($entrega);
            $entrega->bloqueio_motivo = null;
            $entrega->em_revisao = false;
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
                $metadata,
                $key,
                $ocorridoEm
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
            $entrega->status = ProdutoEntregaItem::STATUS_RESERVADO;
            $entrega->bloqueio_motivo = null;
            $entrega->em_revisao = false;
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
        ?string $idempotencyKey = null,
        bool $permitirSemExpedicao = false,
        mixed $ocorridoEm = null,
        array $metadata = []
    ): ProdutoEntregaItem {
        return DB::transaction(function () use ($item, $quantidade, $usuarioId, $observacao, $idempotencyKey, $permitirSemExpedicao, $ocorridoEm, $metadata) {
            $entrega = $this->lockItem($item);

            if ($entrega->status === ProdutoEntregaItem::STATUS_CANCELADO) {
                return $entrega;
            }

            $baseEntregavel = $permitirSemExpedicao
                ? (int) $entrega->quantidade_total
                : (int) $entrega->quantidade_expedida;
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
            $entrega->status = $this->statusOperacional($entrega);
            $entrega->bloqueio_motivo = null;
            $entrega->em_revisao = false;
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
                $metadata,
                $key,
                $ocorridoEm
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

    /**
     * @return array<string,mixed>
     */
    public function estadoAntecipacaoItem(ProdutoEntregaItem $item): array
    {
        $estadoCalculado = $item->getAttribute('_estado_antecipacao_calculado');
        if (is_array($estadoCalculado)) {
            return $estadoCalculado;
        }

        $item->loadMissing($this->relacoesEstadoAntecipacao());
        $pedido = $item->pedido;
        $permitida = $pedido
            && $pedido->isVenda()
            && $pedido->origem_abastecimento === Pedido::ORIGEM_ABASTECIMENTO_FABRICA
            && $item->tipo_origem === ProdutoEntregaItem::ORIGEM_PEDIDO
            && $item->status !== ProdutoEntregaItem::STATUS_CANCELADO;
        $eventosReserva = $item->eventos
            ->where('tipo_evento', ProdutoEntregaEvento::RESERVA_CRIADA)
            ->filter(fn (ProdutoEntregaEvento $evento) => $this->eventoEhAntecipacao($evento));
        $reservas = $eventosReserva
            ->pluck('reserva')
            ->filter()
            ->unique('id')
            ->values();
        $quantidadeReservada = (int) $reservas
            ->where('status', 'ativa')
            ->sum(fn (EstoqueReserva $reserva) => max(0, (int) $reserva->quantidade - (int) $reserva->quantidade_consumida));
        $quantidadeAtendida = (int) $reservas->sum('quantidade_consumida');
        $quantidadeCancelavel = (int) $reservas
            ->filter(fn (EstoqueReserva $reserva) => (
                $reserva->status === 'ativa'
                && (int) $reserva->quantidade_consumida === 0
            ))
            ->sum('quantidade');
        $depositosOrigem = $reservas
            ->filter(fn (EstoqueReserva $reserva) => in_array($reserva->status, ['ativa', 'consumida'], true))
            ->groupBy('id_deposito')
            ->map(function (Collection $reservasDeposito) {
                /** @var EstoqueReserva $primeira */
                $primeira = $reservasDeposito->first();

                return [
                    'id' => (int) $primeira->id_deposito,
                    'nome' => (string) ($primeira->deposito?->nome ?? ''),
                    'quantidade_reservada' => (int) $reservasDeposito
                        ->where('status', 'ativa')
                        ->sum(fn (EstoqueReserva $reserva) => max(0, (int) $reserva->quantidade - (int) $reserva->quantidade_consumida)),
                    'quantidade_atendida' => (int) $reservasDeposito->sum('quantidade_consumida'),
                ];
            })
            ->values();
        $depositoOrigem = $depositosOrigem->first();
        $atendidoOperacional = max(
            (int) $item->quantidade_reservada,
            (int) $item->quantidade_expedida,
            (int) $item->quantidade_entregue
        );

        $estado = [
            'permitida' => (bool) $permitida,
            'ativa' => $quantidadeReservada > 0,
            'cancelavel' => $quantidadeCancelavel > 0,
            'quantidade_cancelavel' => $quantidadeCancelavel,
            'quantidade_reservada' => $quantidadeReservada,
            'quantidade_atendida' => $quantidadeAtendida,
            'quantidade_pendente' => max(0, (int) $item->quantidade_total - $atendidoOperacional),
            'quantidade_aguardando_fabrica' => max(0, (int) $item->quantidade_total - (int) $item->quantidade_recebida),
            'deposito_origem_id' => $depositoOrigem['id'] ?? null,
            'deposito_origem' => $depositoOrigem,
            'depositos_origem' => $depositosOrigem->all(),
            'depositos_disponiveis' => $permitida && $item->id_variacao
                ? $this->disponibilidade->getDisponiveisPorDeposito((int) $item->id_variacao)
                : [],
        ];
        $item->setAttribute('_estado_antecipacao_calculado', $estado);

        return $estado;
    }

    /**
     * @return array<string,mixed>
     */
    public function resumoAntecipacaoPedido(Pedido $pedido): array
    {
        $pedido->loadMissing([
            'entregaItens.pedido:id,tipo,origem_abastecimento',
            'entregaItens.eventos.reserva.deposito:id,nome',
        ]);
        $itens = $pedido->entregaItens
            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
            ->reject(fn (ProdutoEntregaItem $item) => $item->status === ProdutoEntregaItem::STATUS_CANCELADO)
            ->values();
        $estados = $itens->map(fn (ProdutoEntregaItem $item) => [
            'produto_entrega_item_id' => (int) $item->id,
            'pedido_item_id' => (int) $item->pedido_item_id,
            ...$this->estadoAntecipacaoItem($item),
        ]);

        return [
            'permitida' => $pedido->isVenda()
                && $pedido->origem_abastecimento === Pedido::ORIGEM_ABASTECIMENTO_FABRICA,
            'ativa' => $estados->sum('quantidade_reservada') > 0,
            'quantidade_reservada' => (int) $estados->sum('quantidade_reservada'),
            'quantidade_atendida' => (int) $estados->sum('quantidade_atendida'),
            'quantidade_aguardando_fabrica' => (int) $estados->sum('quantidade_aguardando_fabrica'),
            'itens' => $estados->all(),
        ];
    }

    public function resumoPedido(Pedido $pedido): array
    {
        $itens = $pedido->relationLoaded('entregaItens')
            ? $pedido->entregaItens
            : $pedido->entregaItens()->get();

        $principais = $itens
            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
            ->reject(fn (ProdutoEntregaItem $item) => $item->status === ProdutoEntregaItem::STATUS_CANCELADO)
            ->values();
        $devolucoes = $itens
            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_DEVOLUCAO)
            ->reject(fn (ProdutoEntregaItem $item) => $item->status === ProdutoEntregaItem::STATUS_CANCELADO)
            ->values();
        $resumo = $this->resumirItens($principais);
        $fluxoFabrica = $pedido->isReposicao()
            || $pedido->origem_abastecimento === Pedido::ORIGEM_ABASTECIMENTO_FABRICA;

        return [
            ...$resumo,
            'fluxos' => [
                'recebimento_fabrica' => [
                    'quantidade_esperada' => $fluxoFabrica ? $resumo['quantidade_total'] : 0,
                    'quantidade_recebida' => $fluxoFabrica ? $resumo['quantidade_recebida'] : 0,
                    'quantidade_faltante' => $fluxoFabrica ? max(0, $resumo['quantidade_total'] - $resumo['quantidade_recebida']) : 0,
                ],
                'entrega_cliente' => [
                    'quantidade_total' => $resumo['quantidade_total'],
                    'quantidade_reservada' => $resumo['quantidade_reservada'],
                    'quantidade_expedida' => $resumo['quantidade_expedida'],
                    'quantidade_entregue' => $resumo['quantidade_entregue'],
                    'quantidade_faltante' => max(0, $resumo['quantidade_total'] - $resumo['quantidade_entregue']),
                ],
                'devolucoes' => $this->resumirItens($devolucoes),
            ],
        ];
    }

    public function statusOperacionalPedido(Pedido $pedido): array
    {
        $resumo = $this->resumoPedido($pedido);
        $total = (int) $resumo['quantidade_total'];
        $recebido = (int) $resumo['quantidade_recebida'];
        $reservado = (int) $resumo['quantidade_reservada'];
        $expedido = (int) $resumo['quantidade_expedida'];
        $entregue = (int) $resumo['quantidade_entregue'];
        $statusAcompanhamento = (string) ($pedido->statusAtual?->getRawOriginal('status') ?? '');
        $itensPrincipais = $pedido->relationLoaded('entregaItens')
            ? $pedido->entregaItens->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
            : $pedido->entregaItens()->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)->get();
        $contadorExcedido = $itensPrincipais->contains(function (ProdutoEntregaItem $item) {
            $totalItem = (int) $item->quantidade_total;

            return max(
                (int) $item->quantidade_recebida,
                (int) $item->quantidade_reservada,
                (int) $item->quantidade_expedida,
                (int) $item->quantidade_entregue
            ) > $totalItem;
        });
        $fluxoFabrica = $pedido->isReposicao()
            || $pedido->origem_abastecimento === Pedido::ORIGEM_ABASTECIMENTO_FABRICA;
        $conflitoEntregaEstoque = $statusAcompanhamento === PedidoStatus::ENTREGA_ESTOQUE->value
            && (($fluxoFabrica && $recebido < $total) || $expedido > 0 || $entregue > 0);

        $etapaRecebimento = match (true) {
            ! $fluxoFabrica => 'nao_aplicavel',
            $statusAcompanhamento === PedidoStatus::ENTREGA_ESTOQUE->value => 'recebido_estoque',
            $total <= 0, $recebido <= 0 => 'aguardando_fabrica',
            $recebido < $total => 'recebimento_parcial',
            default => 'recebido_estoque',
        };

        $etapaEntrega = null;
        if ($pedido->isVenda()) {
            $etapaEntrega = match (true) {
                $conflitoEntregaEstoque => 'aguardando_entrega_cliente',
                $total > 0 && $entregue >= $total => 'entregue_cliente',
                $entregue > 0 => 'entrega_parcial',
                $expedido > 0 => 'em_entrega',
                $reservado > 0 && (! $fluxoFabrica || $recebido >= $total) => 'pronto_para_entrega',
                $recebido > 0 => 'aguardando_entrega_cliente',
                default => 'aguardando_estoque',
            };
        }

        $proximaAcao = match (true) {
            $conflitoEntregaEstoque || $contadorExcedido || (int) $resumo['pendentes_revisao'] > 0 => 'reconciliar_divergencia',
            $fluxoFabrica && $recebido < $total => 'registrar_recebimento_estoque',
            $pedido->isVenda() && $entregue < $total => 'registrar_entrega_cliente',
            default => null,
        };

        return [
            'recebimento_fabrica' => [
                'etapa' => $etapaRecebimento,
                'quantidade_esperada' => $fluxoFabrica ? $total : 0,
                'quantidade_recebida' => $fluxoFabrica ? $recebido : 0,
                'quantidade_faltante' => $fluxoFabrica ? max(0, $total - $recebido) : 0,
            ],
            'entrega_cliente' => $etapaEntrega === null ? null : [
                'etapa' => $etapaEntrega,
                'quantidade_total' => $total,
                'quantidade_reservada' => min($total, $reservado),
                'quantidade_expedida' => min($total, $expedido),
                'quantidade_entregue' => min($total, $entregue),
                'quantidade_faltante' => max(0, $total - $entregue),
            ],
            'devolucoes' => $resumo['fluxos']['devolucoes'],
            'divergencia' => $conflitoEntregaEstoque || $contadorExcedido || (int) $resumo['pendentes_revisao'] > 0,
            'proxima_acao' => $proximaAcao,
        ];
    }

    public function resumirItens(Collection|EloquentCollection $itens): array
    {
        $total = (int) $itens->sum('quantidade_total');

        $reservada = min($total, (int) $itens->sum('quantidade_reservada'));
        $recebida = min($total, (int) $itens->sum('quantidade_recebida'));
        $expedida = min($total, (int) $itens->sum('quantidade_expedida'));
        $entregue = min($total, (int) $itens->sum('quantidade_entregue'));

        return [
            'total_itens' => $itens->count(),
            'quantidade_total' => $total,
            'quantidade_reservada' => $reservada,
            'quantidade_recebida' => $recebida,
            'quantidade_expedida' => $expedida,
            'quantidade_entregue' => $entregue,
            'pendentes_revisao' => $itens->where('em_revisao', true)->count(),
            'parcial' => $this->temParcialidade($itens, $total),
            'status' => $this->statusAgregado($itens, $total),
        ];
    }

    private function criarOuAtualizarItemPedido(Pedido $pedido, PedidoItem $pedidoItem, ?int $usuarioId = null): ProdutoEntregaItem
    {
        $reposicao = $pedido->isReposicao();
        $aguardaFabrica = $pedido->origem_abastecimento === Pedido::ORIGEM_ABASTECIMENTO_FABRICA;
        $status = ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE;

        $bloqueio = $pedidoItem->id_deposito || $aguardaFabrica
            ? null
            : ($reposicao ? 'Pedido item sem deposito de destino.' : 'Pedido item sem deposito de origem.');

        $entrega = ProdutoEntregaItem::query()->firstOrNew([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
            'pedido_item_id' => $pedidoItem->id,
        ]);
        $possuiEstadoOperacional = $entrega->exists && (
            max(
                (int) $entrega->quantidade_reservada,
                (int) $entrega->quantidade_recebida,
                (int) $entrega->quantidade_expedida,
                (int) $entrega->quantidade_entregue
            ) > 0
            || $entrega->eventos()
                ->where('tipo_evento', '!=', ProdutoEntregaEvento::DEMANDA_CRIADA)
                ->exists()
        );

        $dadosEntrega = [
                'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
                'origem_id' => $pedido->id,
                'pedido_id' => $pedido->id,
                'pedido_item_id' => $pedidoItem->id,
                'id_variacao' => $pedidoItem->id_variacao,
                'quantidade_total' => (int) $pedidoItem->quantidade,
                'previsao_entrega' => $pedido->data_limite_entrega,
        ];

        if (! $possuiEstadoOperacional) {
            $dadosEntrega += [
                'id_deposito_origem' => $aguardaFabrica ? null : ($reposicao ? null : $pedidoItem->id_deposito),
                'id_deposito_destino' => $aguardaFabrica || $reposicao ? $pedidoItem->id_deposito : null,
                'status' => $status,
                'em_revisao' => ! $pedidoItem->id_deposito && ! $aguardaFabrica,
                'bloqueio_motivo' => $bloqueio,
            ];
        }

        $entrega->fill($dadosEntrega);
        if ($possuiEstadoOperacional) {
            $entrega->status = $this->statusOperacional($entrega);
        }
        $entrega->save();

        $this->registrarEvento(
            $entrega,
            ProdutoEntregaEvento::DEMANDA_CRIADA,
            (int) $pedidoItem->quantidade,
            $aguardaFabrica ? null : ($reposicao ? null : $pedidoItem->id_deposito),
            $aguardaFabrica || $reposicao ? $pedidoItem->id_deposito : null,
            null,
            null,
            $usuarioId,
            $aguardaFabrica
                ? 'Demanda de recebimento de fabrica criada.'
                : ($reposicao ? 'Demanda de recebimento de reposicao criada.' : 'Demanda de pedido criada.'),
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
        string $idempotencyKey,
        mixed $ocorridoEm = null
    ): ProdutoEntregaEvento {
        return ProdutoEntregaEvento::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'produto_entrega_item_id' => $item->id,
                'tipo_evento' => $tipo,
                'ocorrido_em' => $ocorridoEm ?: now(),
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

    private function validarContextoAntecipacao(Pedido $pedido, PedidoItem $pedidoItem): void
    {
        if ((int) $pedidoItem->id_pedido !== (int) $pedido->id) {
            throw ValidationException::withMessages([
                'item' => ['O item informado nao pertence a este pedido.'],
            ]);
        }

        if (! $pedido->isVenda()) {
            throw ValidationException::withMessages([
                'pedido' => ['Atendimento antecipado e permitido somente para pedidos de venda.'],
            ]);
        }

        if ($pedido->origem_abastecimento !== Pedido::ORIGEM_ABASTECIMENTO_FABRICA) {
            throw ValidationException::withMessages([
                'pedido' => ['Atendimento antecipado exige pedido com abastecimento vindo da fabrica.'],
            ]);
        }

        if (! $pedidoItem->id_variacao) {
            throw ValidationException::withMessages([
                'item' => ['O item precisa estar vinculado a uma variacao canonica antes da antecipacao.'],
            ]);
        }
    }

    private function eventoEhAntecipacao(ProdutoEntregaEvento $evento): bool
    {
        $metadata = (array) ($evento->metadata_json ?? []);

        return ($metadata['operacao'] ?? null) === self::OPERACAO_ANTECIPACAO
            || str_contains((string) $evento->idempotency_key, ':antecipacao:')
            || str_contains(mb_strtolower((string) $evento->observacao), 'atendimento antecipado');
    }

    /** @return array<int,string> */
    private function relacoesEstadoAntecipacao(): array
    {
        return [
            'pedido:id,tipo,origem_abastecimento',
            'eventos.reserva.deposito:id,nome',
            'depositoOrigem:id,nome',
            'variacao.produto',
        ];
    }

    private function statusRecebimento(int $total, int $recebido): string
    {
        if ($total > 0 && $recebido >= $total) {
            return ProdutoEntregaItem::STATUS_RECEBIDO;
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

        if ($item->status === ProdutoEntregaItem::STATUS_CANCELADO) {
            return ProdutoEntregaItem::STATUS_CANCELADO;
        }

        if ($total > 0 && (int) $item->quantidade_entregue >= $total) {
            return ProdutoEntregaItem::STATUS_ENTREGUE;
        }

        if (
            $total > 0
            && (int) $item->quantidade_recebida >= $total
            && (int) $item->quantidade_expedida === 0
            && (int) $item->quantidade_entregue === 0
        ) {
            return ProdutoEntregaItem::STATUS_RECEBIDO;
        }

        if (
            (int) $item->quantidade_entregue > 0
            || (int) $item->quantidade_expedida > 0
            || ($total > 0 && (int) $item->quantidade_reservada >= $total)
        ) {
            return ProdutoEntregaItem::STATUS_RESERVADO;
        }

        return ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE;
    }

    private function reservarDemandasLiberadasPorRecebimento(ProdutoEntregaItem $recebimento, int $depositoId, int $quantidadeRecebida, ?int $usuarioId): void
    {
        if (!$recebimento->pedido_id || $quantidadeRecebida <= 0) {
            return;
        }

        $pedido = Pedido::query()->select('id', 'tipo')->find($recebimento->pedido_id);
        if (!$pedido?->isVenda()) {
            return;
        }

        $demanda = $recebimento->tipo_origem === ProdutoEntregaItem::ORIGEM_PEDIDO
            ? $recebimento
            : ProdutoEntregaItem::query()
                ->where('pedido_id', $recebimento->pedido_id)
                ->where('id_variacao', $recebimento->id_variacao)
                ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
                ->where('status', '!=', ProdutoEntregaItem::STATUS_CANCELADO)
                ->orderBy('id')
                ->first();

        if (!$demanda || $demanda->em_revisao) {
            return;
        }

        $atendido = max(
            (int) $demanda->quantidade_reservada,
            (int) $demanda->quantidade_expedida,
            (int) $demanda->quantidade_entregue
        );
        $pendente = max(0, (int) $demanda->quantidade_total - $atendido);
        $reservar = min($quantidadeRecebida, $pendente);

        if ($reservar <= 0) {
            return;
        }

        $this->reservarItem(
            $demanda,
            $depositoId,
            $reservar,
            $usuarioId,
            'Reserva criada apos recebimento de fabrica.',
            "recebimento:{$recebimento->id}:recebido:" . (int) $recebimento->quantidade_recebida
        );
    }

    private function finalizarReposicaoSeRecebidaIntegralmente(ProdutoEntregaItem $recebimento, ?int $usuarioId): void
    {
        if (
            !$recebimento->pedido_id
            || $recebimento->tipo_origem !== ProdutoEntregaItem::ORIGEM_PEDIDO
        ) {
            return;
        }

        $pedido = Pedido::query()->find($recebimento->pedido_id);
        if (!$pedido?->isReposicao()) {
            return;
        }

        $ultimoStatus = $pedido->historicoStatus()
            ->latest('data_status')
            ->latest('id')
            ->first();
        $ultimoStatusValor = $ultimoStatus?->getRawOriginal('status');

        if ($ultimoStatusValor === PedidoStatus::CANCELADO->value) {
            return;
        }

        if ($pedido->historicoStatus()->where('status', PedidoStatus::FINALIZADO->value)->exists()) {
            return;
        }

        $itensRecebiveis = ProdutoEntregaItem::query()
            ->where('pedido_id', $pedido->id)
            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
            ->where('status', '!=', ProdutoEntregaItem::STATUS_CANCELADO);

        if (!(clone $itensRecebiveis)->exists()) {
            return;
        }

        $possuiPendente = (clone $itensRecebiveis)
            ->whereColumn('quantidade_recebida', '<', 'quantidade_total')
            ->exists();

        if ($possuiPendente) {
            return;
        }

        if (! $pedido->historicoStatus()->where('status', PedidoStatus::ENTREGA_ESTOQUE->value)->exists()) {
            $pedido->historicoStatus()->create([
                'status' => PedidoStatus::ENTREGA_ESTOQUE,
                'data_status' => now(),
                'usuario_id' => $usuarioId,
                'observacoes' => 'Recebimento integral da fabrica registrado pelo fluxo operacional.',
            ]);
        }

        $pedido->historicoStatus()->create([
            'status' => PedidoStatus::FINALIZADO,
            'data_status' => now(),
            'usuario_id' => $usuarioId,
            'observacoes' => 'Pedido finalizado automaticamente apos recebimento total dos produtos.',
        ]);
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

        if (
            $total > 0
            && (int) $itens->sum('quantidade_recebida') >= $total
            && (int) $itens->sum('quantidade_expedida') === 0
            && (int) $itens->sum('quantidade_entregue') === 0
        ) {
            return ProdutoEntregaItem::STATUS_RECEBIDO;
        }

        if (
            (int) $itens->sum('quantidade_entregue') > 0
            || (int) $itens->sum('quantidade_expedida') > 0
            || ($total > 0 && (int) $itens->sum('quantidade_reservada') >= $total)
        ) {
            return ProdutoEntregaItem::STATUS_RESERVADO;
        }

        return ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE;
    }

    private function temParcialidade(Collection|EloquentCollection $itens, int $total): bool
    {
        if ($total <= 0) {
            return false;
        }

        return $itens->contains(function (ProdutoEntregaItem $item) {
            $itemTotal = (int) $item->quantidade_total;

            return ((int) $item->quantidade_recebida > 0 && (int) $item->quantidade_recebida < $itemTotal)
                || ((int) $item->quantidade_expedida > 0 && (int) $item->quantidade_expedida < $itemTotal)
                || ((int) $item->quantidade_entregue > 0 && (int) $item->quantidade_entregue < $itemTotal);
        });
    }
}
