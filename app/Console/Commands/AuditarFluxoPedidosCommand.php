<?php

namespace App\Console\Commands;

use App\Enums\PedidoStatus;
use App\Models\Pedido;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use Illuminate\Console\Command;

class AuditarFluxoPedidosCommand extends Command
{
    protected $signature = 'pedidos:auditar-fluxo
        {--pedido= : ID ou numero externo do pedido}
        {--json : Exibe o relatorio em JSON}';

    protected $description = 'Audita divergencias entre recebimento da fabrica, estoque e entrega ao cliente sem alterar dados.';

    public function handle(): int
    {
        $filtro = trim((string) $this->option('pedido'));
        $query = Pedido::query()->with([
            'statusAtual',
            'entregaItens.variacao.produto',
            'entregaItens.eventos.movimentacao',
        ]);

        if ($filtro !== '') {
            $query->where(function ($pedido) use ($filtro) {
                if (ctype_digit($filtro)) {
                    $pedido->whereKey((int) $filtro);
                }

                $pedido->orWhere('numero_externo', $filtro);
            });
        }

        $achados = collect();
        $query->orderBy('id')->chunkById(100, function ($pedidos) use ($achados) {
            foreach ($pedidos as $pedido) {
                $this->auditarPedido($pedido)->each(fn (array $achado) => $achados->push($achado));
            }
        });

        if ($this->option('json')) {
            $this->line(json_encode([
                'dry_run' => true,
                'total' => $achados->count(),
                'achados' => $achados->values(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Auditoria do fluxo de pedidos em dry-run; nenhum registro foi alterado.');
        $this->table(
            ['Pedido', 'Item', 'Tipo', 'Detalhes'],
            $achados->map(fn (array $item) => [
                $item['pedido'],
                $item['produto_entrega_item_id'] ?? '-',
                $item['tipo'],
                $item['detalhes'],
            ])->all()
        );
        $this->line('Total de divergencias: '.$achados->count());

        return self::SUCCESS;
    }

    private function auditarPedido(Pedido $pedido)
    {
        $achados = collect();
        $rotuloPedido = $pedido->numero_externo ?: (string) $pedido->id;
        $status = (string) ($pedido->statusAtual?->getRawOriginal('status') ?? '');
        $principais = $pedido->entregaItens
            ->where('tipo_origem', ProdutoEntregaItem::ORIGEM_PEDIDO)
            ->reject(fn (ProdutoEntregaItem $item) => $item->status === ProdutoEntregaItem::STATUS_CANCELADO);

        foreach ($principais as $item) {
            $total = (int) $item->quantidade_total;
            $recebido = (int) $item->quantidade_recebida;
            $expedido = (int) $item->quantidade_expedida;
            $entregue = (int) $item->quantidade_entregue;
            $reservado = (int) $item->quantidade_reservada;

            if ($status === PedidoStatus::ENTREGA_ESTOQUE->value && $recebido < $total) {
                $achados->push($this->achado($rotuloPedido, $item, 'recebimento_ausente',
                    "Status entrega_estoque com {$recebido}/{$total} recebidos."));
            }

            $eventosEstornados = $item->eventos
                ->where('tipo_evento', ProdutoEntregaEvento::ESTORNADO)
                ->pluck('metadata_json')
                ->map(fn ($metadata) => (int) ($metadata['evento_original_id'] ?? 0))
                ->filter()
                ->flip();
            $eventosCliente = $item->eventos
                ->whereIn('tipo_evento', [
                    ProdutoEntregaEvento::EXPEDIDO_CLIENTE,
                    ProdutoEntregaEvento::ENTREGUE_CLIENTE,
                ])
                ->reject(fn (ProdutoEntregaEvento $evento) => $eventosEstornados->has((int) $evento->id));
            $entregaSemSaidaAutorizada = (int) $eventosCliente
                ->where('tipo_evento', ProdutoEntregaEvento::ENTREGUE_CLIENTE)
                ->filter(fn (ProdutoEntregaEvento $evento) => (bool) data_get($evento->metadata_json, 'confirmado_sem_saldo', false))
                ->sum('quantidade');

            if (
                $status === PedidoStatus::ENTREGA_ESTOQUE->value
                && ($eventosCliente->isNotEmpty() || $expedido > 0 || $entregue > 0)
            ) {
                $achados->push($this->achado($rotuloPedido, $item, 'entrega_cliente_conflitante',
                    'Status informa estoque, mas ha expedicao/entrega ao cliente. Eventos ativos: '.($eventosCliente->pluck('id')->implode(', ') ?: 'nao vinculados').'.'));
            }

            $saidas = $eventosCliente->whereNotNull('estoque_movimentacao_id')->pluck('estoque_movimentacao_id')->unique();
            if ($status === PedidoStatus::ENTREGA_ESTOQUE->value && $saidas->isNotEmpty()) {
                $achados->push($this->achado($rotuloPedido, $item, 'saida_fisica_a_revisar',
                    'Preview de estorno condicionado a conferencia fisica. Movimentacoes: '.$saidas->implode(', ').'.'));
            }

            if ($entregue > $expedido + $entregaSemSaidaAutorizada) {
                $achados->push($this->achado($rotuloPedido, $item, 'entrega_sem_saida',
                    "Quantidade entregue {$entregue} maior que expedida {$expedido}."));
            }

            if (max($recebido, $reservado, $expedido, $entregue) > $total) {
                $achados->push($this->achado($rotuloPedido, $item, 'contador_acima_total',
                    "Total {$total}; recebido {$recebido}; reservado {$reservado}; expedido {$expedido}; entregue {$entregue}."));
            }
        }

        $devolucoes = $pedido->entregaItens->where('tipo_origem', ProdutoEntregaItem::ORIGEM_DEVOLUCAO);
        if ($devolucoes->isNotEmpty()) {
            $totalPrincipal = (int) $principais->sum('quantidade_total');
            $totalTodasOrigens = (int) $pedido->entregaItens
                ->reject(fn (ProdutoEntregaItem $item) => $item->status === ProdutoEntregaItem::STATUS_CANCELADO)
                ->sum('quantidade_total');

            if ($totalTodasOrigens > $totalPrincipal) {
                $achados->push([
                    'pedido' => $rotuloPedido,
                    'pedido_id' => (int) $pedido->id,
                    'produto_entrega_item_id' => null,
                    'tipo' => 'devolucao_fora_total_venda',
                    'detalhes' => "Venda canonica {$totalPrincipal}; todas as origens {$totalTodasOrigens}. Devolucoes devem permanecer separadas.",
                ]);
            }
        }

        return $achados;
    }

    private function achado(string $pedido, ProdutoEntregaItem $item, string $tipo, string $detalhes): array
    {
        return [
            'pedido' => $pedido,
            'pedido_id' => (int) $item->pedido_id,
            'produto_entrega_item_id' => (int) $item->id,
            'pedido_item_id' => $item->pedido_item_id ? (int) $item->pedido_item_id : null,
            'id_variacao' => (int) $item->id_variacao,
            'produto' => $item->variacao?->produto?->nome,
            'tipo' => $tipo,
            'detalhes' => $detalhes,
        ];
    }
}
