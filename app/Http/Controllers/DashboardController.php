<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Pedido;
use App\Services\LogService;
use App\Services\MetricasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function resumo(): JsonResponse
    {
        $inicio = microtime(true);

        $usuarioId = AuthHelper::getUsuarioId();
        $filtrarPorUsuario = !AuthHelper::hasPermissao('pedidos.visualizar.todos');
        $cacheKey = $filtrarPorUsuario
            ? "dashboard_resumo_usuario_{$usuarioId}"
            : 'dashboard_resumo_admin';

        $resumo = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($filtrarPorUsuario, $usuarioId, $cacheKey, $inicio) {
            LogService::debug('DashboardCache', 'Gerando novo cache', ['cacheKey' => $cacheKey]);

            $agora = now();
            $inicioMes = $agora->copy()->startOfMonth();
            $fimMes = $agora->copy()->endOfMonth();

            $query = Pedido::with(['cliente', 'statusAtual'])
                ->whereBetween('data_pedido', [$inicioMes, $fimMes]);

            if ($filtrarPorUsuario) {
                $query->where('id_usuario', $usuarioId);
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

            $statusCount = $pedidos->groupBy(function ($pedido) {
                return optional($pedido->statusAtual)->status?->value ?? 'sem_status';
            });

            $ultimosPedidos = Pedido::with(['cliente', 'statusAtual'])
                ->when($filtrarPorUsuario, fn($q) => $q->where('id_usuario', $usuarioId))
                ->orderByDesc('data_pedido')
                ->take(5)
                ->get()
                ->map(function ($p) {
                    return [
                        'cliente' => optional($p->cliente)->nome ?? 'Cliente não informado',
                        'valor'   => number_format($p->valor_total ?? 0, 2, ',', '.'),
                        'status'  => optional($p->statusAtual)->status ?? 'sem_status',
                    ];
                });

            $fim = microtime(true);
            LogService::debug('DashboardCache', 'Resumo calculado', [
                'cacheKey' => $cacheKey,
                'duration_ms' => round(($fim - $inicio) * 1000, 2)
            ]);

            return [
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
            ];
        });

        $duracao = microtime(true) - $inicio;

        LogService::debug('DashboardCache', 'Resumo carregado via cache ou computado', [
            'cacheKey' => $cacheKey,
            'duration_ms' => round(($duracao) * 1000, 2)
        ]);

        MetricasService::registrar($cacheKey, 'dashboard_resumo', 'cache_hit', $duracao * 1000, $usuarioId);

        return response()->json($resumo);
    }

}
