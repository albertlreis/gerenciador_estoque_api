<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Pedido;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function resumo(): JsonResponse
    {
        $agora = now();
        $inicioMes = $agora->copy()->startOfMonth();
        $fimMes = $agora->copy()->endOfMonth();

        $query = Pedido::with('cliente')
            ->whereBetween('data_pedido', [$inicioMes, $fimMes]);

        $filtrarPorUsuario = !AuthHelper::hasPermissao('pedidos.visualizar.todos');

        if ($filtrarPorUsuario) {
            $query->where('id_usuario', AuthHelper::getUsuarioId());
        }

        $pedidos = $query->get();
        $totalPedidos = $pedidos->count();
        $valorTotal = $pedidos->sum('valor_total');
        $ticketMedio = $totalPedidos > 0 ? $valorTotal / $totalPedidos : 0;

        $clientesUnicos = $pedidos
            ->pluck('cliente.id')
            ->unique()
            ->filter()
            ->count();

        $statusCount = collect($pedidos->groupBy('status'))->map(fn($group) => $group->count())->all();

        $ultimosPedidos = Pedido::with('cliente')
            ->when($filtrarPorUsuario, fn($q) => $q->where('id_usuario', AuthHelper::getUsuarioId()))
            ->orderByDesc('data_pedido')
            ->take(5)
            ->get()
            ->map(function ($p) {
                return [
                    'cliente' => optional($p->cliente)->nome ?? 'Cliente não informado',
                    'valor'   => number_format($p->valor_total ?? 0, 2, ',', '.'),
                    'status'  => $p->status,
                ];
            });

        return response()->json([
            'kpis' => [
                'pedidosMes'       => $totalPedidos,
                'valorMes'         => $valorTotal,
                'clientesUnicos'   => $clientesUnicos,
                'ticketMedio'      => $ticketMedio,
                'totalConfirmado'  => $statusCount['confirmado'] ?? 0,
                'totalCancelado'   => $statusCount['cancelado'] ?? 0,
                'totalRascunho'    => $statusCount['rascunho'] ?? 0,
            ],
            'ultimosPedidos' => $ultimosPedidos,
            'statusGrafico'  => $statusCount,
            'pedidosMes'     => $pedidos,
            'clientesMes'    => $pedidos->pluck('cliente')->unique('id')->filter()->values(),
        ]);
    }
}
