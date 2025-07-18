<?php

namespace App\Services\Relatorios;

use App\Models\Pedido;
use Illuminate\Support\Carbon;

class PedidosRelatorioService
{
    /**
     * Retorna os pedidos filtrados por período e outros critérios.
     *
     * @param array $filtros data_inicio, data_fim, cliente_id, status, vendedor_id
     * @return array
     */
    public function listarPedidosPorPeriodo(array $filtros): array
    {
        $query = Pedido::with(['cliente', 'statusAtual'])
            ->when(!empty($filtros['data_inicio']), fn($q) =>
            $q->whereDate('data_pedido', '>=', Carbon::parse($filtros['data_inicio']))
            )
            ->when(!empty($filtros['data_fim']), fn($q) =>
            $q->whereDate('data_pedido', '<=', Carbon::parse($filtros['data_fim']))
            )
            ->when(!empty($filtros['cliente_id']), fn($q) =>
            $q->where('id_cliente', $filtros['cliente_id'])
            )
            ->when(!empty($filtros['vendedor_id']), fn($q) =>
            $q->where('id_usuario', $filtros['vendedor_id'])
            )
            ->when(!empty($filtros['status']), function ($q) use ($filtros) {
                $q->whereHas('statusAtual', function ($query) use ($filtros) {
                    $query->where('status', $filtros['status']);
                });
            })
            ->orderByDesc('data_pedido');

        return $query->get()->map(function ($pedido) {
            return [
                'numero'   => $pedido->numero_externo ?? $pedido->id,
                'data'     => optional($pedido->data_pedido)->format('Y-m-d'),
                'cliente'  => $pedido->cliente->nome ?? '-',
                'total'    => $pedido->valor_total,
                'status'   => $pedido->status,
            ];
        })->toArray();
    }
}
