<?php

namespace App\Services;

use App\Enums\PedidoStatus;
use App\Models\Pedido;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PedidoEstornoOperacionalService
{
    public const TIPO_RECEBIMENTO = 'recebimento_fabrica';

    public const TIPO_ENTREGA = 'entrega_cliente';

    public const MODO_MANTER_EM_ENTREGA = 'manter_em_entrega';

    public const MODO_DEVOLVER_ESTOQUE = 'devolver_estoque';

    private const OBS_RECEBIMENTO_INTEGRAL = 'Recebimento integral da fabrica registrado pelo fluxo operacional.';

    private const OBS_REPOSICAO_FINALIZADA = 'Pedido finalizado automaticamente apos recebimento total dos produtos.';

    private const OBS_ENTREGA_CONCLUIDA = 'Entrega ao cliente concluida pela nota de entrega.';

    private const OBS_ENTREGA_INICIADA = 'Entrega parcial ao cliente iniciada pela nota de entrega.';

    public function __construct(
        private readonly EntregaProdutoService $entregas,
        private readonly EstoqueMovimentacaoService $movimentacoes,
        private readonly EstoqueDisponibilidadeService $disponibilidade
    ) {}

    /** @return array{pedido_id:int,itens:array<int,array<string,mixed>>} */
    public function preview(Pedido $pedido): array
    {
        $pedido->loadMissing([
            'entregaItens.eventos',
            'entregaItens.variacao.produto',
            'entregaItens.depositoOrigem:id,nome',
            'entregaItens.depositoDestino:id,nome',
        ]);

        return [
            'pedido_id' => (int) $pedido->id,
            'itens' => $pedido->entregaItens
                ->whereIn('tipo_origem', [
                    ProdutoEntregaItem::ORIGEM_PEDIDO,
                    ProdutoEntregaItem::ORIGEM_PEDIDO_FABRICA,
                ])
                ->reject(fn (ProdutoEntregaItem $item) => $item->status === ProdutoEntregaItem::STATUS_CANCELADO)
                ->map(fn (ProdutoEntregaItem $item) => $this->previewItem($item))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string,mixed> */
    public function executar(Pedido $pedido, array $dados, int $usuarioId): array
    {
        return DB::transaction(function () use ($pedido, $dados, $usuarioId) {
            $pedido = Pedido::query()->whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $item = ProdutoEntregaItem::query()
                ->whereKey((int) $dados['produto_entrega_item_id'])
                ->where('pedido_id', $pedido->id)
                ->whereIn('tipo_origem', [
                    ProdutoEntregaItem::ORIGEM_PEDIDO,
                    ProdutoEntregaItem::ORIGEM_PEDIDO_FABRICA,
                ])
                ->lockForUpdate()
                ->first();

            if (! $item) {
                throw ValidationException::withMessages([
                    'produto_entrega_item_id' => ['O item não pertence ao fluxo operacional deste pedido.'],
                ]);
            }

            $tipo = (string) $dados['tipo'];
            $modo = $tipo === self::TIPO_ENTREGA ? (string) ($dados['modo'] ?? '') : null;
            $quantidade = (int) $dados['quantidade'];
            $motivo = trim((string) $dados['motivo']);
            $chave = trim((string) $dados['idempotency_key']);
            $payloadHash = hash('sha256', json_encode([
                'pedido_id' => (int) $pedido->id,
                'item_id' => (int) $item->id,
                'tipo' => $tipo,
                'modo' => $modo,
                'quantidade' => $quantidade,
                'motivo' => $motivo,
            ], JSON_UNESCAPED_UNICODE));
            $prefixo = "estorno-operacional:{$chave}:";

            $item->load(['eventos', 'variacao.produto', 'depositoOrigem:id,nome', 'depositoDestino:id,nome']);
            $existente = $item->eventos
                ->first(fn (ProdutoEntregaEvento $evento) => str_starts_with((string) $evento->idempotency_key, $prefixo));
            if ($existente) {
                if (data_get($existente->metadata_json, 'payload_hash') !== $payloadHash) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['Chave de idempotência já utilizada com outro payload.'],
                    ]);
                }

                return $this->resultado($pedido, $this->recarregarItem($item), true);
            }

            $preview = $this->previewItem($item);
            $operacao = $tipo === self::TIPO_RECEBIMENTO ? $preview['recebimento'] : $preview['entrega'];
            $maximo = $tipo === self::TIPO_ENTREGA
                ? (int) data_get($operacao, "modos.{$modo}.quantidade_maxima", 0)
                : (int) ($operacao['quantidade_maxima'] ?? 0);

            if ($quantidade <= 0 || $quantidade > $maximo) {
                throw ValidationException::withMessages([
                    'quantidade' => [($operacao['mensagem_bloqueio'] ?? null)
                        ?: "Quantidade excede o saldo estornável ({$maximo})."],
                ]);
            }

            $eventos = ProdutoEntregaEvento::query()
                ->where('produto_entrega_item_id', $item->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();
            $indice = 0;
            $eventosEstornados = [];
            $movimentacoesEstorno = [];

            if ($tipo === self::TIPO_RECEBIMENTO) {
                $fatias = $this->alocarRecebimentos($item, $eventos, $quantidade);
                foreach ($fatias as $fatia) {
                    $estorno = $this->registrarFatia(
                        $item,
                        $fatia['evento'],
                        $fatia['quantidade'],
                        $usuarioId,
                        $motivo,
                        $prefixo.(++$indice),
                        $payloadHash,
                        $tipo,
                        null
                    );
                    $eventosEstornados[] = $estorno['evento'];
                    if ($estorno['movimentacao']) {
                        $movimentacoesEstorno[] = $estorno['movimentacao'];
                    }
                }
                $item->quantidade_recebida = max(0, (int) $item->quantidade_recebida - $quantidade);
            } else {
                $entregas = $this->alocarEventos(
                    $eventos,
                    ProdutoEntregaEvento::ENTREGUE_CLIENTE,
                    $quantidade,
                    $modo === self::MODO_DEVOLVER_ESTOQUE
                );
                foreach ($entregas as $fatia) {
                    $estorno = $this->registrarFatia(
                        $item,
                        $fatia['evento'],
                        $fatia['quantidade'],
                        $usuarioId,
                        $motivo,
                        $prefixo.(++$indice),
                        $payloadHash,
                        $tipo,
                        $modo,
                        false
                    );
                    $eventosEstornados[] = $estorno['evento'];
                }
                $item->quantidade_entregue = max(0, (int) $item->quantidade_entregue - $quantidade);

                if ($modo === self::MODO_DEVOLVER_ESTOQUE) {
                    $expedicoes = $this->alocarEventos(
                        $eventos,
                        ProdutoEntregaEvento::EXPEDIDO_CLIENTE,
                        $quantidade
                    );
                    foreach ($expedicoes as $fatia) {
                        $estorno = $this->registrarFatia(
                            $item,
                            $fatia['evento'],
                            $fatia['quantidade'],
                            $usuarioId,
                            $motivo,
                            $prefixo.(++$indice),
                            $payloadHash,
                            $tipo,
                            $modo
                        );
                        $eventosEstornados[] = $estorno['evento'];
                        if ($estorno['movimentacao']) {
                            $movimentacoesEstorno[] = $estorno['movimentacao'];
                        }
                    }
                    $item->quantidade_expedida = max(0, (int) $item->quantidade_expedida - $quantidade);
                }
            }

            $item->status = $this->entregas->statusOperacional($item);
            $item->save();
            $statusRemovidos = $this->reconciliarStatusPedido($pedido, $usuarioId);

            logAuditoria('pedido_estorno_operacional', 'Estorno operacional no Pedido #'.($pedido->numero_externo ?: $pedido->id).'.', [
                'acao' => 'estorno_operacional',
                'nivel' => 'warn',
                'pedido_id' => (int) $pedido->id,
                'produto_entrega_item_id' => (int) $item->id,
                'tipo' => $tipo,
                'modo' => $modo,
                'quantidade' => $quantidade,
                'motivo' => $motivo,
                'idempotency_key' => $chave,
                'eventos_estorno_ids' => $eventosEstornados,
                'movimentacoes_estorno_ids' => $movimentacoesEstorno,
                'status_historico_removidos' => $statusRemovidos,
            ], $pedido);

            return $this->resultado($pedido, $this->recarregarItem($item), false, [
                'eventos_estorno_ids' => $eventosEstornados,
                'movimentacoes_estorno_ids' => $movimentacoesEstorno,
                'status_historico_removidos' => $statusRemovidos,
            ]);
        });
    }

    /** @return array<string,mixed> */
    private function previewItem(ProdutoEntregaItem $item): array
    {
        $eventos = $item->eventos instanceof Collection ? $item->eventos : collect();
        $recebimentosAtivos = $this->eventosAtivos($eventos, ProdutoEntregaEvento::RECEBIDO_ESTOQUE);
        $entregasAtivas = $this->eventosAtivos($eventos, ProdutoEntregaEvento::ENTREGUE_CLIENTE);
        $entregasComEstoque = $entregasAtivas->reject(
            fn (array $fatia) => str_ends_with((string) $fatia['evento']->idempotency_key, ':entregar-sem-saldo')
        );
        $expedicoesAtivas = $this->eventosAtivos($eventos, ProdutoEntregaEvento::EXPEDIDO_CLIENTE)
            ->filter(fn (array $fatia) => $fatia['evento']->estoque_movimentacao_id !== null);

        $limiteDependencias = max(
            0,
            (int) $item->quantidade_recebida - max((int) $item->quantidade_expedida, (int) $item->quantidade_entregue)
        );
        $recebimentoFisico = $this->quantidadeRecebimentoFisicamenteEstornavel($item, $recebimentosAtivos);
        $maxRecebimento = min($limiteDependencias, $recebimentoFisico);
        $maxManterEntrega = min((int) $item->quantidade_entregue, (int) $entregasAtivas->sum('saldo'));
        $maxDevolverEstoque = min(
            $maxManterEntrega,
            (int) $entregasComEstoque->sum('saldo'),
            (int) $expedicoesAtivas->sum('saldo')
        );

        $bloqueioRecebimento = null;
        if ((int) $item->quantidade_recebida > 0 && $maxRecebimento <= 0) {
            $bloqueioRecebimento = $limiteDependencias <= 0
                ? 'Estorne primeiro as entregas ou expedições vinculadas a este item.'
                : 'O depósito não possui saldo livre suficiente para estornar o recebimento.';
        }

        return [
            'produto_entrega_item_id' => (int) $item->id,
            'produto' => $item->variacao?->produto?->nome ?: $item->variacao?->nome ?: "Item #{$item->id}",
            'referencia' => $item->variacao?->referencia,
            'recebimento' => [
                'quantidade_registrada' => (int) $item->quantidade_recebida,
                'quantidade_maxima' => $maxRecebimento,
                'bloqueado' => (int) $item->quantidade_recebida > 0 && $maxRecebimento <= 0,
                'mensagem_bloqueio' => $bloqueioRecebimento,
                'deposito' => $item->depositoDestino
                    ? ['id' => (int) $item->depositoDestino->id, 'nome' => $item->depositoDestino->nome]
                    : null,
            ],
            'entrega' => [
                'quantidade_registrada' => (int) $item->quantidade_entregue,
                'quantidade_maxima' => $maxManterEntrega,
                'bloqueado' => (int) $item->quantidade_entregue > 0 && $maxManterEntrega <= 0,
                'mensagem_bloqueio' => (int) $item->quantidade_entregue > 0 && $maxManterEntrega <= 0
                    ? 'Nenhum evento ativo de entrega pode ser estornado.'
                    : null,
                'modos' => [
                    self::MODO_MANTER_EM_ENTREGA => [
                        'quantidade_maxima' => $maxManterEntrega,
                        'bloqueado' => $maxManterEntrega <= 0,
                        'mensagem_bloqueio' => $maxManterEntrega <= 0 ? 'Não há entrega ativa para estornar.' : null,
                    ],
                    self::MODO_DEVOLVER_ESTOQUE => [
                        'quantidade_maxima' => $maxDevolverEstoque,
                        'bloqueado' => $maxDevolverEstoque <= 0,
                        'mensagem_bloqueio' => $maxDevolverEstoque <= 0
                            ? 'Esta entrega não possui expedição física estornável.'
                            : null,
                    ],
                ],
                'deposito_retorno' => $item->depositoOrigem
                    ? ['id' => (int) $item->depositoOrigem->id, 'nome' => $item->depositoOrigem->nome]
                    : null,
            ],
        ];
    }

    private function eventosAtivos(Collection $eventos, string $tipo): Collection
    {
        $estornadoPorEvento = $eventos
            ->where('tipo_evento', ProdutoEntregaEvento::ESTORNADO)
            ->filter(fn (ProdutoEntregaEvento $evento) => data_get($evento->metadata_json, 'evento_original_id'))
            ->groupBy(fn (ProdutoEntregaEvento $evento) => (int) data_get($evento->metadata_json, 'evento_original_id'))
            ->map(fn (Collection $grupo) => (int) $grupo->sum('quantidade'));

        return $eventos
            ->where('tipo_evento', $tipo)
            ->sortByDesc('id')
            ->map(fn (ProdutoEntregaEvento $evento) => [
                'evento' => $evento,
                'saldo' => max(0, (int) $evento->quantidade - (int) ($estornadoPorEvento[$evento->id] ?? 0)),
            ])
            ->filter(fn (array $fatia) => $fatia['saldo'] > 0)
            ->values();
    }

    private function quantidadeRecebimentoFisicamenteEstornavel(ProdutoEntregaItem $item, Collection $eventos): int
    {
        $disponivelPorDeposito = [];
        $total = 0;
        foreach ($eventos as $fatia) {
            $depositoId = (int) $fatia['evento']->id_deposito_destino;
            if ($depositoId <= 0) {
                continue;
            }
            $disponivelPorDeposito[$depositoId] ??= max(
                0,
                $this->disponibilidade->getDisponivel((int) $item->id_variacao, $depositoId)
            );
            $quantidade = min((int) $fatia['saldo'], $disponivelPorDeposito[$depositoId]);
            $total += $quantidade;
            $disponivelPorDeposito[$depositoId] -= $quantidade;
        }

        return $total;
    }

    private function alocarRecebimentos(ProdutoEntregaItem $item, Collection $eventos, int $quantidade): array
    {
        $ativos = $this->eventosAtivos($eventos, ProdutoEntregaEvento::RECEBIDO_ESTOQUE);
        $disponivelPorDeposito = [];
        $fatias = [];
        $restante = $quantidade;
        foreach ($ativos as $fatia) {
            $depositoId = (int) $fatia['evento']->id_deposito_destino;
            if ($depositoId <= 0) {
                continue;
            }
            $disponivelPorDeposito[$depositoId] ??= max(
                0,
                $this->disponibilidade->getDisponivel((int) $item->id_variacao, $depositoId)
            );
            $selecionada = min($restante, (int) $fatia['saldo'], $disponivelPorDeposito[$depositoId]);
            if ($selecionada > 0) {
                $fatias[] = ['evento' => $fatia['evento'], 'quantidade' => $selecionada];
                $restante -= $selecionada;
                $disponivelPorDeposito[$depositoId] -= $selecionada;
            }
            if ($restante <= 0) {
                break;
            }
        }
        if ($restante > 0) {
            throw ValidationException::withMessages([
                'quantidade' => ['O saldo livre mudou e não comporta mais o estorno solicitado.'],
            ]);
        }

        return $fatias;
    }

    private function alocarEventos(Collection $eventos, string $tipo, int $quantidade, bool $exigirEstoque = false): array
    {
        $ativos = $this->eventosAtivos($eventos, $tipo);
        if ($exigirEstoque) {
            $ativos = $ativos->reject(
                fn (array $fatia) => str_ends_with((string) $fatia['evento']->idempotency_key, ':entregar-sem-saldo')
            );
        }
        if ($tipo === ProdutoEntregaEvento::EXPEDIDO_CLIENTE) {
            $ativos = $ativos->filter(fn (array $fatia) => $fatia['evento']->estoque_movimentacao_id !== null);
        }

        $fatias = [];
        $restante = $quantidade;
        foreach ($ativos as $fatia) {
            $selecionada = min($restante, (int) $fatia['saldo']);
            if ($selecionada > 0) {
                $fatias[] = ['evento' => $fatia['evento'], 'quantidade' => $selecionada];
                $restante -= $selecionada;
            }
            if ($restante <= 0) {
                break;
            }
        }
        if ($restante > 0) {
            throw ValidationException::withMessages([
                'quantidade' => ['Os eventos operacionais mudaram e não comportam mais o estorno solicitado.'],
            ]);
        }

        return $fatias;
    }

    /** @return array{evento:int,movimentacao:int|null} */
    private function registrarFatia(
        ProdutoEntregaItem $item,
        ProdutoEntregaEvento $original,
        int $quantidade,
        int $usuarioId,
        string $motivo,
        string $idempotencyKey,
        string $payloadHash,
        string $tipo,
        ?string $modo,
        bool $estornarMovimentacao = true
    ): array {
        $movimentacao = null;
        if ($estornarMovimentacao && $original->estoque_movimentacao_id) {
            try {
                $movimentacao = $this->movimentacoes->estornarMovimentacao(
                    (int) $original->estoque_movimentacao_id,
                    $usuarioId,
                    $motivo,
                    $quantidade
                );
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'quantidade' => [$exception->getMessage()],
                ]);
            }
        }

        $evento = ProdutoEntregaEvento::create([
            'produto_entrega_item_id' => $item->id,
            'tipo_evento' => ProdutoEntregaEvento::ESTORNADO,
            'ocorrido_em' => now(),
            'quantidade' => $quantidade,
            'id_deposito_origem' => $original->id_deposito_destino,
            'id_deposito_destino' => $original->id_deposito_origem,
            'estoque_reserva_id' => $original->estoque_reserva_id,
            'estoque_movimentacao_id' => $movimentacao?->id,
            'usuario_id' => $usuarioId,
            'observacao' => $motivo,
            'metadata_json' => [
                'evento_original_id' => (int) $original->id,
                'movimentacao_original_id' => $original->estoque_movimentacao_id
                    ? (int) $original->estoque_movimentacao_id
                    : null,
                'estorno_parcial' => $quantidade < (int) $original->quantidade,
                'operacao' => $tipo,
                'modo' => $modo,
                'payload_hash' => $payloadHash,
            ],
            'idempotency_key' => $idempotencyKey,
        ]);

        return ['evento' => (int) $evento->id, 'movimentacao' => $movimentacao?->id];
    }

    /** @return array<int,int> */
    private function reconciliarStatusPedido(Pedido $pedido, int $usuarioId): array
    {
        $itens = ProdutoEntregaItem::query()
            ->where('pedido_id', $pedido->id)
            // Os marcos automáticos do pedido são calculados sobre os itens comerciais.
            // Itens de pedido_fabrica podem espelhar a mesma demanda e não devem duplicar o total.
            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
            ->where('status', '!=', ProdutoEntregaItem::STATUS_CANCELADO)
            ->get();
        $total = (int) $itens->sum('quantidade_total');
        $recebido = (int) $itens->sum('quantidade_recebida');
        $expedido = (int) $itens->sum('quantidade_expedida');
        $entregue = (int) $itens->sum('quantidade_entregue');
        $remover = collect();

        if ($recebido < $total) {
            $remover->push(...$pedido->historicoStatus()
                ->where(function ($query) {
                    $query->where(function ($status) {
                        $status->where('status', PedidoStatus::ENTREGA_ESTOQUE->value)
                            ->where('observacoes', self::OBS_RECEBIMENTO_INTEGRAL);
                    })->orWhere(function ($status) {
                        $status->where('status', PedidoStatus::FINALIZADO->value)
                            ->where('observacoes', self::OBS_REPOSICAO_FINALIZADA);
                    });
                })->lockForUpdate()->get()->all());
        }

        if ($entregue < $total) {
            $remover->push(...$pedido->historicoStatus()
                ->where('status', PedidoStatus::ENTREGA_CLIENTE->value)
                ->where('observacoes', self::OBS_ENTREGA_CONCLUIDA)
                ->lockForUpdate()
                ->get()
                ->all());
        }

        if ($expedido <= 0 && $entregue <= 0) {
            $remover->push(...$pedido->historicoStatus()
                ->where('status', PedidoStatus::ENVIO_CLIENTE->value)
                ->where('observacoes', self::OBS_ENTREGA_INICIADA)
                ->lockForUpdate()
                ->get()
                ->all());
        } elseif (! $pedido->historicoStatus()->where('status', PedidoStatus::ENVIO_CLIENTE->value)->exists()) {
            $pedido->historicoStatus()->create([
                'status' => PedidoStatus::ENVIO_CLIENTE,
                'data_status' => now(),
                'usuario_id' => $usuarioId,
                'observacoes' => 'Entrega parcial ao cliente restaurada por estorno operacional.',
            ]);
        }

        $remover = $remover->unique('id');
        $ids = $remover->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $remover->each->delete();

        return $ids;
    }

    /** @return array<string,mixed> */
    private function resultado(Pedido $pedido, ProdutoEntregaItem $item, bool $repetido, array $extra = []): array
    {
        return [
            'pedido_id' => (int) $pedido->id,
            'produto_entrega_item_id' => (int) $item->id,
            'repetido' => $repetido,
            'item' => [
                'quantidade_recebida' => (int) $item->quantidade_recebida,
                'quantidade_expedida' => (int) $item->quantidade_expedida,
                'quantidade_entregue' => (int) $item->quantidade_entregue,
                'status' => (string) $item->status,
            ],
            'preview' => $this->previewItem($item),
            ...$extra,
        ];
    }

    private function recarregarItem(ProdutoEntregaItem $item): ProdutoEntregaItem
    {
        return $item->fresh([
            'eventos',
            'variacao.produto',
            'depositoOrigem:id,nome',
            'depositoDestino:id,nome',
        ]);
    }
}
