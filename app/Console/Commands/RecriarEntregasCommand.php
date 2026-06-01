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

class RecriarEntregasCommand extends Command
{
    protected $signature = 'entregas:recriar {--dry-run : Apenas contabiliza a recriacao} {--apply : Apaga e recria as tabelas centrais}';

    protected $description = 'Recria produto_entrega_itens e produto_entrega_eventos a partir dos dados operacionais existentes.';

    /** @var array<string,int> */
    private array $contadores = [
        'itens_criados' => 0,
        'eventos_criados' => 0,
        'itens_em_revisao' => 0,
        'movimentacoes_sem_item' => 0,
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply || (bool) $this->option('dry-run');

        if ($apply && $this->option('dry-run')) {
            $this->warn('Opcoes --apply e --dry-run informadas juntas; executando em dry-run.');
            $apply = false;
            $dryRun = true;
        }

        $this->info($dryRun ? 'Recriacao de entregas em dry-run.' : 'Recriacao de entregas com persistencia.');
        $this->exibirDiagnostico();

        if (! $apply) {
            return self::SUCCESS;
        }

        DB::transaction(function () {
            ProdutoEntregaEvento::query()->delete();
            ProdutoEntregaItem::query()->delete();

            $this->recriarPedidoItens();
            $this->recriarReservas();
            $this->recriarMovimentacoes();
            $this->recriarFabrica();
            $this->projetarStatusFinais();
        });

        $this->table(
            ['Resultado', 'Total'],
            collect($this->contadores)->map(fn ($total, $nome) => [$nome, $total])->all()
        );
        $this->info('Tabelas centrais de entrega recriadas.');

        return self::SUCCESS;
    }

    private function exibirDiagnostico(): void
    {
        $dados = [
            'produto_entrega_itens_atual' => ProdutoEntregaItem::query()->count(),
            'produto_entrega_eventos_atual' => ProdutoEntregaEvento::query()->count(),
            'pedido_itens' => PedidoItem::query()->count(),
            'reservas_vinculadas' => EstoqueReserva::query()->whereNotNull('pedido_item_id')->count(),
            'movimentacoes_vinculadas' => EstoqueMovimentacao::query()
                ->where(fn ($q) => $q->whereNotNull('pedido_item_id')->orWhereNotNull('pedido_id'))
                ->count(),
            'pedido_fabrica_itens' => PedidoFabricaItem::query()->count(),
            'pedido_itens_sem_deposito' => PedidoItem::query()->whereNull('id_deposito')->count(),
            'pedido_fabrica_itens_sem_deposito' => PedidoFabricaItem::query()->whereNull('deposito_id')->count(),
        ];

        $this->table(['Origem', 'Total'], collect($dados)->map(fn ($total, $origem) => [$origem, $total])->all());
    }

    private function recriarPedidoItens(): void
    {
        PedidoItem::query()
            ->with('pedido:id,tipo,data_limite_entrega')
            ->orderBy('id')
            ->chunkById(200, function ($itens) {
                foreach ($itens as $item) {
                    $reposicao = ($item->pedido?->tipo ?? Pedido::TIPO_VENDA) === Pedido::TIPO_REPOSICAO;
                    $emRevisao = ! $item->id_deposito || ! $item->id_variacao;
                    $bloqueio = $emRevisao
                        ? ($reposicao ? 'Recriacao: item de reposicao sem deposito de destino ou variacao.' : 'Recriacao: item de venda sem deposito de origem ou variacao.')
                        : null;

                    $entrega = ProdutoEntregaItem::query()->create([
                        'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
                        'origem_id' => $item->id_pedido,
                        'pedido_id' => $item->id_pedido,
                        'pedido_item_id' => $item->id,
                        'id_variacao' => $item->id_variacao,
                        'quantidade_total' => (int) $item->quantidade,
                        'id_deposito_origem' => $reposicao ? null : $item->id_deposito,
                        'id_deposito_destino' => $reposicao ? $item->id_deposito : null,
                        'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
                        'em_revisao' => $emRevisao,
                        'previsao_entrega' => $item->pedido?->data_limite_entrega,
                        'bloqueio_motivo' => $bloqueio,
                    ]);

                    $this->contadores['itens_criados']++;
                    $this->contadores['itens_em_revisao'] += $emRevisao ? 1 : 0;

                    $this->evento($entrega, ProdutoEntregaEvento::DEMANDA_CRIADA, (int) $item->quantidade, "recriar:pedido-item:{$item->id}:demanda", [
                        'pedido_item_id' => $item->id,
                    ]);
                }
            });
    }

    private function recriarReservas(): void
    {
        EstoqueReserva::query()
            ->whereNotNull('pedido_item_id')
            ->whereIn('status', ['ativa', 'consumida'])
            ->orderBy('id')
            ->chunkById(200, function ($reservas) {
                foreach ($reservas as $reserva) {
                    $entrega = ProdutoEntregaItem::query()
                        ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
                        ->where('pedido_item_id', $reserva->pedido_item_id)
                        ->first();

                    if (! $entrega) {
                        continue;
                    }

                    $entrega->quantidade_reservada = min(
                        (int) $entrega->quantidade_total,
                        (int) $entrega->quantidade_reservada + (int) $reserva->quantidade
                    );
                    $entrega->id_deposito_origem = $entrega->id_deposito_origem ?: $reserva->id_deposito;
                    $entrega->status = $this->statusSimplificado($entrega);
                    $entrega->save();

                    $this->evento($entrega, ProdutoEntregaEvento::RESERVA_CRIADA, (int) $reserva->quantidade, "recriar:reserva:{$reserva->id}", [
                        'status_reserva' => $reserva->status,
                    ], $reserva->id);
                }
            });
    }

    private function recriarMovimentacoes(): void
    {
        $tiposSaida = [
            EstoqueMovimentacaoTipo::SAIDA->value,
            EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value,
            EstoqueMovimentacaoTipo::CONSIGNACAO_ENVIO->value,
            EstoqueMovimentacaoTipo::ASSISTENCIA_ENVIO->value,
        ];
        $tiposEntrada = [
            EstoqueMovimentacaoTipo::ENTRADA->value,
            EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
            EstoqueMovimentacaoTipo::CONSIGNACAO_DEVOLUCAO->value,
            EstoqueMovimentacaoTipo::ASSISTENCIA_RETORNO->value,
        ];

        EstoqueMovimentacao::query()
            ->whereIn('tipo', [...$tiposSaida, ...$tiposEntrada])
            ->where(fn ($q) => $q->whereNotNull('pedido_item_id')->orWhereNotNull('pedido_id'))
            ->orderBy('id')
            ->chunkById(200, function ($movimentacoes) use ($tiposSaida) {
                foreach ($movimentacoes as $mov) {
                    $entrega = $this->entregaPorMovimentacao($mov);

                    if (! $entrega) {
                        $this->contadores['movimentacoes_sem_item']++;
                        continue;
                    }

                    if (in_array($mov->tipo, $tiposSaida, true)) {
                        $entrega->quantidade_expedida = min(
                            (int) $entrega->quantidade_total,
                            (int) $entrega->quantidade_expedida + (int) $mov->quantidade
                        );
                        $entrega->id_deposito_origem = $entrega->id_deposito_origem ?: $mov->id_deposito_origem;
                        $tipoEvento = match ($mov->tipo) {
                            EstoqueMovimentacaoTipo::CONSIGNACAO_ENVIO->value => ProdutoEntregaEvento::ENVIADO_CONSIGNACAO,
                            EstoqueMovimentacaoTipo::ASSISTENCIA_ENVIO->value => ProdutoEntregaEvento::ENVIADO_ASSISTENCIA,
                            default => ProdutoEntregaEvento::EXPEDIDO_CLIENTE,
                        };
                    } else {
                        $entrega->quantidade_recebida = min(
                            (int) $entrega->quantidade_total,
                            (int) $entrega->quantidade_recebida + (int) $mov->quantidade
                        );
                        $entrega->id_deposito_destino = $entrega->id_deposito_destino ?: $mov->id_deposito_destino;
                        $tipoEvento = match ($mov->tipo) {
                            EstoqueMovimentacaoTipo::CONSIGNACAO_DEVOLUCAO->value => ProdutoEntregaEvento::RETORNADO_CONSIGNACAO,
                            EstoqueMovimentacaoTipo::ASSISTENCIA_RETORNO->value => ProdutoEntregaEvento::RETORNADO_ASSISTENCIA,
                            default => ProdutoEntregaEvento::RECEBIDO_ESTOQUE,
                        };
                    }

                    $entrega->status = $this->statusSimplificado($entrega);
                    $entrega->save();

                    $this->evento($entrega, $tipoEvento, (int) $mov->quantidade, "recriar:movimentacao:{$mov->id}", [
                        'tipo_movimentacao' => $mov->tipo,
                    ], null, $mov->id, $mov->id_deposito_origem, $mov->id_deposito_destino);
                }
            });
    }

    private function recriarFabrica(): void
    {
        PedidoFabricaItem::query()
            ->orderBy('id')
            ->chunkById(200, function ($itens) {
                foreach ($itens as $item) {
                    $total = (int) $item->quantidade;
                    $recebido = min($total, (int) $item->quantidade_entregue);
                    $emRevisao = ! $item->deposito_id || ! $item->produto_variacao_id;

                    $entrega = ProdutoEntregaItem::query()->create([
                        'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO_FABRICA,
                        'origem_id' => $item->pedido_fabrica_id,
                        'pedido_id' => $item->pedido_venda_id,
                        'pedido_fabrica_item_id' => $item->id,
                        'id_variacao' => $item->produto_variacao_id,
                        'quantidade_total' => $total,
                        'quantidade_recebida' => $recebido,
                        'id_deposito_destino' => $item->deposito_id,
                        'status' => $recebido >= $total && $total > 0
                            ? ProdutoEntregaItem::STATUS_RECEBIDO
                            : ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
                        'em_revisao' => $emRevisao,
                        'bloqueio_motivo' => $emRevisao ? 'Recriacao: item de fabrica sem deposito ou variacao.' : null,
                    ]);

                    $this->contadores['itens_criados']++;
                    $this->contadores['itens_em_revisao'] += $emRevisao ? 1 : 0;

                    $this->evento($entrega, ProdutoEntregaEvento::DEMANDA_CRIADA, $total, "recriar:fabrica-item:{$item->id}:demanda");

                    if ($recebido > 0) {
                        $this->evento($entrega, ProdutoEntregaEvento::RECEBIDO_ESTOQUE, $recebido, "recriar:fabrica-item:{$item->id}:recebido", [
                            'pedido_fabrica_item_id' => $item->id,
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
                            $this->evento($item, ProdutoEntregaEvento::CANCELADO, 0, "recriar:pedido:{$pedido->id}:item:{$item->id}:cancelado");
                            continue;
                        }

                        if ((int) $item->quantidade_expedida >= (int) $item->quantidade_total) {
                            $item->quantidade_entregue = (int) $item->quantidade_total;
                            $item->status = ProdutoEntregaItem::STATUS_ENTREGUE;
                            $item->save();
                            $this->evento($item, ProdutoEntregaEvento::ENTREGUE_CLIENTE, (int) $item->quantidade_total, "recriar:pedido:{$pedido->id}:item:{$item->id}:entregue");
                        } else {
                            $item->em_revisao = true;
                            $item->status = $this->statusSimplificado($item);
                            $item->bloqueio_motivo = 'Recriacao: pedido marcado como entregue/finalizado sem expedicao confiavel suficiente.';
                            $item->save();
                        }
                    }
                }
            });
    }

    private function entregaPorMovimentacao(EstoqueMovimentacao $mov): ?ProdutoEntregaItem
    {
        if ($mov->pedido_item_id) {
            $entrega = ProdutoEntregaItem::query()
                ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
                ->where('pedido_item_id', $mov->pedido_item_id)
                ->first();

            if ($entrega) {
                return $entrega;
            }
        }

        if (! $mov->pedido_id) {
            return null;
        }

        return ProdutoEntregaItem::query()
            ->where('pedido_id', $mov->pedido_id)
            ->where('id_variacao', $mov->id_variacao)
            ->orderBy('id')
            ->first();
    }

    private function statusSimplificado(ProdutoEntregaItem $item): string
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
        ?int $movimentacaoId = null,
        ?int $depositoOrigem = null,
        ?int $depositoDestino = null
    ): void {
        $criado = ProdutoEntregaEvento::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'produto_entrega_item_id' => $item->id,
                'tipo_evento' => $tipo,
                'quantidade' => max(0, $quantidade),
                'id_deposito_origem' => $depositoOrigem ?? $item->id_deposito_origem,
                'id_deposito_destino' => $depositoDestino ?? $item->id_deposito_destino,
                'estoque_reserva_id' => $reservaId,
                'estoque_movimentacao_id' => $movimentacaoId,
                'usuario_id' => null,
                'observacao' => 'Evento criado pela recriacao do controle central.',
                'metadata_json' => $metadata === [] ? null : $metadata,
            ]
        );

        $this->contadores['eventos_criados'] += $criado->wasRecentlyCreated ? 1 : 0;
    }
}
