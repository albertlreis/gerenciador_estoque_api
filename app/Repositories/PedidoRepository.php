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
        $query = Pedido::with(['cliente', 'parceiro', 'usuario', 'statusAtual', 'historicoStatus', 'devolucoes:id,pedido_id',]);

        if (!AuthHelper::hasPermissao('pedidos.visualizar.todos')) {
            $query->where('id_usuario', auth()->id());
        }

        if ($request->filled('status')) {
            $query->whereHas('statusAtual', fn($q) => $q->where('status', $request->status));
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
                    ->orWhereHas('usuario', fn($sub) =>
                    $sub->whereRaw("LOWER(nome) COLLATE utf8mb4_general_ci LIKE ?", ["%$busca%"])
                    );
            });
        }

        return $query;
    }
}
