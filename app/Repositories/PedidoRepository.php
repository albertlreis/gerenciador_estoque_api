<?php

namespace App\Repositories;

use App\Helpers\AuthHelper;
use App\Models\Pedido;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Repositório responsável por consultar pedidos com filtros e paginação.
 */
class PedidoRepository
{
    private const STATUS_OPERACIONAIS_PERMITIDOS = [
        'aguardando_fabrica', 'recebimento_parcial', 'recebido_estoque',
        'aguardando_entrega_cliente', 'em_entrega', 'entrega_parcial',
        'entregue_cliente', 'finalizado', 'cancelado',
    ];

    private const STATUS_OPERACIONAIS_EM_FLUXO_POR_PRIORIDADE = [
        'entregue_cliente',
        'entrega_parcial',
        'em_entrega',
        'aguardando_entrega_cliente',
        'recebido_estoque',
        'recebimento_parcial',
        'aguardando_fabrica',
    ];
    /**
     * Retorna um builder de pedidos com filtros aplicados.
     *
     * @param Request $request
     * @return Builder
     */
    public function comFiltros(Request $request): Builder
    {
        $query = Pedido::with(['cliente', 'parceiro', 'fornecedor', 'usuario', 'statusAtual', 'statusPrevisoes', 'historicoStatus', 'devolucoes:id,pedido_id', 'entregaItens']);

        if (!AuthHelper::podeVisualizarPedidosDeTodos()) {
            $query->where('id_usuario', auth()->id());
        }

        if (!$request->boolean('incluir_consignacoes')) {
            $query->where(function (Builder $q) {
                $q->whereDoesntHave('consignacoes')
                    ->orWhereHas('consignacoes', fn (Builder $sub) => $sub->where('status', 'comprado'));
            });
        }

        if (in_array($request->input('tipo'), [Pedido::TIPO_VENDA, Pedido::TIPO_REPOSICAO], true)) {
            $query->where('tipo', $request->input('tipo'));
        }

        $statusOperacionais = $this->normalizarStatusOperacionais($request);
        if ($statusOperacionais !== []) {
            $query->where(function (Builder $filtros) use ($statusOperacionais) {
                foreach ($statusOperacionais as $status) {
                    $filtros->orWhere(function (Builder $pedido) use ($status) {
                        $this->aplicarFiltroOperacional($pedido, $status);
                    });
                }
            });
        }

        if ($request->filled('data_inicio')) {
            $query->where('data_pedido', '>=', $request->input('data_inicio') . ' 00:00:00');
        }

        if ($request->filled('data_fim')) {
            $query->where('data_pedido', '<=', $request->input('data_fim') . ' 23:59:59');
        }

        if ($request->filled('busca')) {
            $busca = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $request->busca));

            $query->where(function ($q) use ($busca) {
                $q->orWhereRaw("LOWER(numero_externo) COLLATE utf8mb4_general_ci LIKE ?", ["%$busca%"])
                    ->orWhereHas('cliente', fn($sub) =>
                    $sub->whereRaw("LOWER(nome) COLLATE utf8mb4_general_ci LIKE ?", ["%$busca%"])
                    )
                    ->orWhereHas('parceiro', fn($sub) =>
                    $sub->whereRaw("LOWER(nome) COLLATE utf8mb4_general_ci LIKE ?", ["%$busca%"])
                    )
                    ->orWhereHas('fornecedor', fn($sub) =>
                    $sub->whereRaw("LOWER(nome) COLLATE utf8mb4_general_ci LIKE ?", ["%$busca%"])
                    )
                    ->orWhereHas('usuario', fn($sub) =>
                    $sub->whereRaw("LOWER(nome) COLLATE utf8mb4_general_ci LIKE ?", ["%$busca%"])
                    );
            });
        }

        return $query;
    }

    /** @return list<string> */
    private function normalizarStatusOperacionais(Request $request): array
    {
        $valores = $request->input('status_operacionais');
        if (!is_array($valores)) {
            $valores = [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($status) => is_string($status) ? trim($status) : '', $valores),
            static fn (string $status) => in_array($status, self::STATUS_OPERACIONAIS_PERMITIDOS, true)
        )));
    }

    private function aplicarFiltroOperacional(Builder $query, string $status): void
    {
        $emFluxo = fn (Builder $pedido) => $pedido->whereDoesntHave(
            'statusAtual',
            fn (Builder $statusAtual) => $statusAtual->whereIn('status', ['finalizado', 'cancelado'])
        );

        if ($status === 'finalizado') {
            $query->whereHas('statusAtual', fn (Builder $statusAtual) => $statusAtual->where('status', 'finalizado'));

            return;
        }

        if ($status === 'cancelado') {
            $query->whereHas('statusAtual', fn (Builder $statusAtual) => $statusAtual->where('status', 'cancelado'));

            return;
        }

        $emFluxo($query);

        $this->aplicarCondicaoOperacional($query, $status);

        $indicePrioridade = array_search($status, self::STATUS_OPERACIONAIS_EM_FLUXO_POR_PRIORIDADE, true);
        if ($indicePrioridade === false) {
            return;
        }

        foreach (array_slice(self::STATUS_OPERACIONAIS_EM_FLUXO_POR_PRIORIDADE, 0, $indicePrioridade) as $statusPrioritario) {
            $query->whereNot(function (Builder $pedido) use ($statusPrioritario) {
                $this->aplicarCondicaoOperacional($pedido, $statusPrioritario);
            });
        }
    }

    private function aplicarCondicaoOperacional(Builder $query, string $status): void
    {
        $principal = fn (Builder $item) => $item
            ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
            ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO);

        match ($status) {
            'aguardando_fabrica' => $query
                ->where(function (Builder $pedido) {
                    $pedido->where('origem_abastecimento', Pedido::ORIGEM_ABASTECIMENTO_FABRICA)
                        ->orWhere('tipo', Pedido::TIPO_REPOSICAO);
                })
                ->whereHas('entregaItens', $principal)
                ->whereDoesntHave('entregaItens', fn (Builder $item) => $item
                    ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                    ->where('quantidade_recebida', '>', 0)),
            'recebimento_parcial' => $query
                ->where(function (Builder $pedido) {
                    $pedido->where('origem_abastecimento', Pedido::ORIGEM_ABASTECIMENTO_FABRICA)
                        ->orWhere('tipo', Pedido::TIPO_REPOSICAO);
                })
                ->whereHas('entregaItens', fn (Builder $item) => $item
                    ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                    ->where('quantidade_recebida', '>', 0))
                ->whereHas('entregaItens', fn (Builder $item) => $item
                    ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                    ->whereColumn('quantidade_recebida', '<', 'quantidade_total')),
            'recebido_estoque' => $query->where(function (Builder $recebido) {
                $recebido->whereHas('statusAtual', fn (Builder $statusAtual) => $statusAtual->where('status', 'entrega_estoque'))
                    ->orWhere(function (Builder $integralSemReserva) {
                        $integralSemReserva
                            ->where(function (Builder $pedido) {
                                $pedido->where('origem_abastecimento', Pedido::ORIGEM_ABASTECIMENTO_FABRICA)
                                    ->orWhere('tipo', Pedido::TIPO_REPOSICAO);
                            })
                            ->whereHas('entregaItens', fn (Builder $item) => $item
                                ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                                ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO))
                            ->whereDoesntHave('entregaItens', fn (Builder $item) => $item
                                ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                                ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                                ->whereColumn('quantidade_recebida', '<', 'quantidade_total'))
                            ->whereDoesntHave('entregaItens', fn (Builder $item) => $item
                                ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                                ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                                ->where(fn (Builder $movimento) => $movimento
                                    ->where('quantidade_reservada', '>', 0)
                                    ->orWhere('quantidade_expedida', '>', 0)
                                    ->orWhere('quantidade_entregue', '>', 0)));
                    });
            })->whereDoesntHave('entregaItens', fn (Builder $item) => $item
                ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                ->where(fn (Builder $movimento) => $movimento
                    ->where('quantidade_expedida', '>', 0)
                    ->orWhere('quantidade_entregue', '>', 0))),
            'aguardando_entrega_cliente' => $query
                ->where('tipo', Pedido::TIPO_VENDA)
                ->whereDoesntHave('entregaItens', fn (Builder $item) => $item
                    ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                    ->where('quantidade_entregue', '>', 0))
                ->whereDoesntHave('entregaItens', fn (Builder $item) => $item
                    ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                    ->where('quantidade_expedida', '>', 0))
                ->where(function (Builder $pedido) {
                    $pedido->where(function (Builder $estoque) {
                        $estoque->where('origem_abastecimento', Pedido::ORIGEM_ABASTECIMENTO_ESTOQUE)
                            ->whereHas('entregaItens', fn (Builder $item) => $item
                                ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                                ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                                ->where('quantidade_reservada', '>', 0));
                    })->orWhere(function (Builder $fabrica) {
                        $fabrica->where('origem_abastecimento', Pedido::ORIGEM_ABASTECIMENTO_FABRICA)
                            ->whereHas('entregaItens', fn (Builder $item) => $item
                                ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                                ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO))
                            ->whereDoesntHave('entregaItens', fn (Builder $item) => $item
                                ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                                ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                                ->whereColumn('quantidade_recebida', '<', 'quantidade_total'));
                    });
                }),
            'em_entrega' => $query
                ->where('tipo', Pedido::TIPO_VENDA)
                ->whereHas('entregaItens', fn (Builder $item) => $item
                    ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                    ->where('quantidade_expedida', '>', 0))
                ->whereDoesntHave('entregaItens', fn (Builder $item) => $item
                    ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                    ->where('quantidade_entregue', '>', 0)),
            'entrega_parcial' => $query
                ->where('tipo', Pedido::TIPO_VENDA)
                ->whereHas('entregaItens', fn (Builder $item) => $item
                    ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                    ->where('quantidade_entregue', '>', 0))
                ->whereHas('entregaItens', fn (Builder $item) => $item
                    ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                    ->whereColumn('quantidade_entregue', '<', 'quantidade_total')),
            'entregue_cliente' => $query
                ->where('tipo', Pedido::TIPO_VENDA)
                ->whereHas('entregaItens', $principal)
                ->whereDoesntHave('entregaItens', fn (Builder $item) => $item
                    ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                    ->whereColumn('quantidade_entregue', '<', 'quantidade_total')),
            'divergencia' => $query->where(function (Builder $pedido) {
                $pedido->whereHas('entregaItens', fn (Builder $item) => $item->where('em_revisao', true))
                    ->orWhereHas('entregaItens', fn (Builder $item) => $item
                        ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                        ->where(function (Builder $contador) {
                            $contador->whereColumn('quantidade_recebida', '>', 'quantidade_total')
                                ->orWhereColumn('quantidade_reservada', '>', 'quantidade_total')
                                ->orWhereColumn('quantidade_expedida', '>', 'quantidade_total')
                                ->orWhereColumn('quantidade_entregue', '>', 'quantidade_total');
                        }))
                    ->orWhere(function (Builder $legado) {
                        $legado->whereHas('statusAtual', fn (Builder $status) => $status->where('status', 'entrega_estoque'))
                            ->whereHas('entregaItens', fn (Builder $item) => $item
                                ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                                ->where(function (Builder $movimento) {
                                    $movimento->where('quantidade_expedida', '>', 0)
                                        ->orWhere('quantidade_entregue', '>', 0)
                                        ->orWhereColumn('quantidade_recebida', '<', 'quantidade_total');
                                }));
                    });
            }),
            default => null,
        };
    }
}
