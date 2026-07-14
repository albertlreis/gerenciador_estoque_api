<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Services\PedidoReconciliacaoPreviewService;
use Illuminate\Http\JsonResponse;

final class PedidoReconciliacaoPreviewController extends Controller
{
    public function __construct(
        private readonly PedidoReconciliacaoPreviewService $service,
    ) {}

    /**
     * Preview estritamente read-only, resolvido pelo ID interno ou numero externo.
     */
    public function show(Pedido $pedido): JsonResponse
    {
        abort_unless(config('pedidos.fluxo_operacional_v2_enabled'), 404);

        return response()->json([
            'data' => $this->service->preview($pedido),
        ])->header('Cache-Control', 'no-store');
    }
}
