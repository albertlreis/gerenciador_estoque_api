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

        if ($request->filled('status')) {
            $query->whereHas('statusAtual', fn($q) => $q->where('status', $request->status));
        }

        if (in_array($request->input('tipo'), [Pedido::TIPO_VENDA, Pedido::TIPO_REPOSICAO], true)) {
            $query->where('tipo', $request->input('tipo'));
        }

        if ($request->filled('status_operacional')) {
            $this->aplicarFiltroOperacional($query, (string) $request->input('status_operacional'));
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

    private function aplicarFiltroOperacional(Builder $query, string $status): void
    {
        $principal = fn (Builder $item) => $item
            ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
            ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO);

        match ($status) {
            'aguardando_fabrica', 'recebimento_pendente' => $query
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
                ->whereHas('entregaItens', fn (Builder $item) => $item
                    ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                    ->where('quantidade_recebida', '>', 0))
                ->whereHas('entregaItens', fn (Builder $item) => $item
                    ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                    ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                    ->whereColumn('quantidade_recebida', '<', 'quantidade_total')),
            'recebido_estoque' => $query->where(function (Builder $recebido) use ($principal) {
                $recebido->where(function (Builder $integral) use ($principal) {
                    $integral->whereHas('entregaItens', $principal)
                        ->whereDoesntHave('entregaItens', fn (Builder $item) => $item
                            ->where('tipo_origem', \App\Models\ProdutoEntregaItem::ORIGEM_PEDIDO)
                            ->where('status', '!=', \App\Models\ProdutoEntregaItem::STATUS_CANCELADO)
                            ->whereColumn('quantidade_recebida', '<', 'quantidade_total'));
                })->orWhereHas('statusAtual', fn (Builder $status) => $status->where('status', 'entrega_estoque'));
            }),
            'aguardando_entrega_cliente' => $query
                ->where('tipo', Pedido::TIPO_VENDA)
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
