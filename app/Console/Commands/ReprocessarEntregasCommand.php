<?php

namespace App\Console\Commands;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\PedidoStatus;
use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueReserva;
use App\Models\Pedido;
use App\Models\PedidoFabricaItem;
use App\Models\PedidoItem;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReprocessarEntregasCommand extends Command
{
    protected $signature = 'entregas:reprocessar {--dry-run : Apenas contabiliza o que seria reprocessado} {--apply : Persiste os registros centrais}';

    protected $description = 'Reprocessa pedidos, reservas, movimentacoes e recebimentos legados para o controle central de entregas.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = !$apply || (bool) $this->option('dry-run');

        if ($apply && $this->option('dry-run')) {
            $this->warn('Opcoes --apply e --dry-run informadas juntas; executando em dry-run.');
            $apply = false;
        }

        $this->info($dryRun ? 'Reprocessamento em dry-run.' : 'Reprocessamento com persistencia.');

        $contadores = [
            'pedido_itens' => PedidoItem::query()->count(),
            'reservas' => EstoqueReserva::query()->whereNotNull('pedido_item_id')->count(),
            'movimentacoes' => EstoqueMovimentacao::query()
                ->where(function ($q) {
                    $q->whereNotNull('pedido_item_id')->orWhereNotNull('pedido_id');
                })
                ->count(),
            'fabrica_itens' => PedidoFabricaItem::query()->count(),
        ];

        $this->table(['Origem', 'Total'], collect($contadores)->map(fn ($total, $origem) => [$origem, $total])->all());

        if (!$apply) {
            return self::SUCCESS;
        }

        DB::transaction(function () {
            $this->reprocessarPedidoItens();
            $this->reprocessarReservas();
            $this->reprocessarMovimentacoes();
            $this->reprocessarFabrica();
            $this->projetarStatusFinais();
        });

        $this->info('Reprocessamento concluido.');

        return self::SUCCESS;
    }

    private function reprocessarPedidoItens(): void
    {
        PedidoItem::query()
            ->with('pedido:id,data_limite_entrega')
            ->orderBy('id')
            ->chunkById(200, function ($itens) {
                foreach ($itens as $item) {
                    $entrega = ProdutoEntregaItem::query()->updateOrCreate(
                        [
                            'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
                            'pedido_item_id' => $item->id,
                        ],
                        [
                            'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
                            'origem_id' => $item->id_pedido,
                            'pedido_id' => $item->id_pedido,
                            'pedido_item_id' => $item->id,
                            'id_variacao' => $item->id_variacao,
                            'quantidade_total' => (int) $item->quantidade,
                            'id_deposito_origem' => $item->id_deposito,
                            'previsao_entrega' => $item->pedido?->data_limite_entrega,
                            'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
                            'em_revisao' => ! $item->id_deposito,
                            'bloqueio_motivo' => $item->id_deposito ? null : 'Reprocessamento: pedido item sem deposito.',
                        ]
                    );

                    $this->evento($entrega, ProdutoEntregaEvento::DEMANDA_CRIADA, (int) $item->quantidade, "reprocessar:pedido-item:{$item->id}:demanda", [
                        'pedido_item_id' => $item->id,
                    ]);
                }
            });
    }

    private function reprocessarReservas(): void
    {
        EstoqueReserva::query()
            ->whereNotNull('pedido_item_id')
            ->orderBy('id')
            ->chunkById(200, function ($reservas) {
                foreach ($reservas as $reserva) {
                    $entrega = ProdutoEntregaItem::query()
                        ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
                        ->where('pedido_item_id', $reserva->pedido_item_id)
                        ->first();
                    if (!$entrega) {
                        continue;
                    }

                    $reservada = (int) EstoqueReserva::query()
                        ->where('pedido_item_id', $reserva->pedido_item_id)
                        ->whereIn('status', ['ativa', 'consumida'])
                        ->sum('quantidade');

                    $entrega->quantidade_reservada = min((int) $entrega->quantidade_total, $reservada);
                    if (! $entrega->em_revisao) {
                        $entrega->status = $entrega->quantidade_reservada >= $entrega->quantidade_total
                            ? ProdutoEntregaItem::STATUS_RESERVADO
                            : ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE;
                    }
                    $entrega->save();

                    $this->evento($entrega, ProdutoEntregaEvento::RESERVA_CRIADA, (int) $reserva->quantidade, "reprocessar:reserva:{$reserva->id}", [
                        'estoque_reserva_id' => $reserva->id,
                        'status_reserva' => $reserva->status,
                    ], $reserva->id);
                }
            });
    }

    private function reprocessarMovimentacoes(): void
    {
        $tiposSaida = [
            EstoqueMovimentacaoTipo::SAIDA->value,
            EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value,
            EstoqueMovimentacaoTipo::CONSIGNACAO_ENVIO->value,
        ];

        EstoqueMovimentacao::query()
            ->whereIn('tipo', $tiposSaida)
            ->where(function ($q) {
                $q->whereNotNull('pedido_item_id')->orWhereNotNull('pedido_id');
            })
            ->orderBy('id')
            ->chunkById(200, function ($movimentacoes) {
                foreach ($movimentacoes as $mov) {
                    $entrega = null;
                    if ($mov->pedido_item_id) {
                        $entrega = ProdutoEntregaItem::query()
                            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
                            ->where('pedido_item_id', $mov->pedido_item_id)
                            ->first();
                    }

                    if (!$entrega && $mov->pedido_id) {
                        $entrega = ProdutoEntregaItem::query()
                            ->where('pedido_id', $mov->pedido_id)
                            ->where('id_variacao', $mov->id_variacao)
                            ->orderBy('id')
                            ->first();
                    }

                    if (!$entrega) {
                        continue;
                    }

                    $expedida = (int) EstoqueMovimentacao::query()
                        ->whereIn('tipo', [
                            EstoqueMovimentacaoTipo::SAIDA->value,
                            EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value,
                            EstoqueMovimentacaoTipo::CONSIGNACAO_ENVIO->value,
                        ])
                        ->where(function ($q) use ($entrega) {
                            $q->where('pedido_item_id', $entrega->pedido_item_id)
                                ->orWhere(function ($sub) use ($entrega) {
                                    $sub->where('pedido_id', $entrega->pedido_id)
                                        ->where('id_variacao', $entrega->id_variacao);
                                });
                        })
                        ->sum('quantidade');

                    $entrega->quantidade_expedida = min((int) $entrega->quantidade_total, $expedida);
                    $entrega->status = ProdutoEntregaItem::STATUS_RESERVADO;
                    $entrega->bloqueio_motivo = null;
                    $entrega->save();

                    $tipoEvento = $mov->tipo === EstoqueMovimentacaoTipo::CONSIGNACAO_ENVIO->value
                        ? ProdutoEntregaEvento::ENVIADO_CONSIGNACAO
                        : ProdutoEntregaEvento::EXPEDIDO_CLIENTE;

                    $this->evento($entrega, $tipoEvento, (int) $mov->quantidade, "reprocessar:movimentacao:{$mov->id}", [
                        'estoque_movimentacao_id' => $mov->id,
                        'tipo_movimentacao' => $mov->tipo,
                    ], null, $mov->id);
                }
            });
    }

    private function reprocessarFabrica(): void
    {
        PedidoFabricaItem::query()
            ->orderBy('id')
            ->chunkById(200, function ($itens) {
                foreach ($itens as $item) {
                    $recebido = (int) $item->quantidade_entregue;
                    $status = $recebido >= (int) $item->quantidade
                        ? ProdutoEntregaItem::STATUS_RECEBIDO
                        : ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE;

                    $entrega = ProdutoEntregaItem::query()->updateOrCreate(
                        ['pedido_fabrica_item_id' => $item->id],
                        [
                            'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO_FABRICA,
                            'origem_id' => $item->pedido_fabrica_id,
                            'pedido_id' => $item->pedido_venda_id,
                            'pedido_fabrica_item_id' => $item->id,
                            'id_variacao' => $item->produto_variacao_id,
                            'quantidade_total' => (int) $item->quantidade,
                            'quantidade_recebida' => $recebido,
                            'id_deposito_destino' => $item->deposito_id,
                            'status' => $status,
                            'em_revisao' => ! $item->deposito_id,
                            'bloqueio_motivo' => $item->deposito_id ? null : 'Reprocessamento: pedido de fabrica sem deposito.',
                        ]
                    );

                    $this->evento($entrega, ProdutoEntregaEvento::DEMANDA_CRIADA, (int) $item->quantidade, "reprocessar:fabrica-item:{$item->id}:demanda");

                    if ($recebido > 0) {
                        $this->evento($entrega, ProdutoEntregaEvento::RECEBIDO_ESTOQUE, $recebido, "reprocessar:fabrica-item:{$item->id}:recebido-sem-mov", [
                            'sem_movimentacao_estoque' => true,
                        ]);
                    }
                }
            });
    }

    private function projetarStatusFinais(): void
    {
        Pedido::query()
            ->whereHas('historicoStatus', fn ($q) => $q->whereIn('status', [
                PedidoStatus::ENTREGA_CLIENTE->value,
                PedidoStatus::FINALIZADO->value,
                PedidoStatus::CANCELADO->value,
            ]))
            ->with('statusAtual')
            ->orderBy('id')
            ->chunkById(200, function ($pedidos) {
                foreach ($pedidos as $pedido) {
                    $status = $pedido->statusAtual?->status?->value ?? $pedido->statusAtual?->status;
                    $itens = ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->get();

                    foreach ($itens as $item) {
                        if ($status === PedidoStatus::CANCELADO->value) {
                            $item->status = ProdutoEntregaItem::STATUS_CANCELADO;
                            $item->save();
                            $this->evento($item, ProdutoEntregaEvento::CANCELADO, 0, "reprocessar:pedido:{$pedido->id}:item:{$item->id}:cancelado");
                            continue;
                        }

                        if ((int) $item->quantidade_expedida >= (int) $item->quantidade_total) {
                            $item->quantidade_entregue = (int) $item->quantidade_total;
                            $item->status = ProdutoEntregaItem::STATUS_ENTREGUE;
                            $item->save();
                            $this->evento($item, ProdutoEntregaEvento::ENTREGUE_CLIENTE, (int) $item->quantidade_total, "reprocessar:pedido:{$pedido->id}:item:{$item->id}:entregue");
                        } else {
                            $item->em_revisao = true;
                            $item->bloqueio_motivo = 'Reprocessamento: pedido marcado como entregue/finalizado sem expedicao central ou movimentacao suficiente.';
                            $item->save();
                        }
                    }
                }
            });
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function evento(
        ProdutoEntregaItem $item,
        string $tipo,
        int $quantidade,
        string $key,
        array $metadata = [],
        ?int $reservaId = null,
        ?int $movimentacaoId = null
    ): void {
        ProdutoEntregaEvento::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'produto_entrega_item_id' => $item->id,
                'tipo_evento' => $tipo,
                'quantidade' => max(0, $quantidade),
                'id_deposito_origem' => $item->id_deposito_origem,
                'id_deposito_destino' => $item->id_deposito_destino,
                'estoque_reserva_id' => $reservaId,
                'estoque_movimentacao_id' => $movimentacaoId,
                'usuario_id' => null,
                'observacao' => 'Evento criado por reprocessamento historico.',
                'metadata_json' => $metadata === [] ? null : $metadata,
            ]
        );
    }
}
