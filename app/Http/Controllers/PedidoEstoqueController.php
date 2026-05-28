<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Services\EntregaProdutoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PedidoEstoqueController extends Controller
{
    public function __construct(
        private readonly EntregaProdutoService $entregas,
    ) {}

    /**
     * Reserva estoque em lote para todos os itens do pedido pelo fluxo central.
     */
    public function reservar(Pedido $pedido, Request $request): JsonResponse
    {
        $usuarioId = auth()->id();

        return DB::transaction(function () use ($pedido, $usuarioId) {
            $itens = $this->entregas->reservarPedido($pedido, $usuarioId ? (int) $usuarioId : null);

            return response()->json([
                'message' => 'Reserva criada com sucesso.',
                'itens_processados' => $itens->count(),
            ]);
        });
    }

    /**
     * Expede em lote: consome reserva e baixa estoque uma unica vez.
     */
    public function expedir(Pedido $pedido, Request $request): JsonResponse
    {
        $usuarioId = auth()->id();

        return DB::transaction(function () use ($pedido, $usuarioId) {
            $itens = $this->entregas->expedirPedido($pedido, $usuarioId ? (int) $usuarioId : null);

            return response()->json([
                'message' => 'Pedido expedido com sucesso.',
                'itens_processados' => $itens->count(),
            ]);
        });
    }

    /**
     * Cancela as entregas centrais pendentes do pedido.
     */
    public function cancelarReservas(Pedido $pedido, Request $request): JsonResponse
    {
        $usuarioId = auth()->id();
        $motivo = $request->input('motivo') ?? 'pedido_cancelado';

        return DB::transaction(function () use ($pedido, $usuarioId, $motivo) {
            $pedido->loadMissing('entregaItens');

            foreach ($pedido->entregaItens as $item) {
                $this->entregas->cancelarItem($item, $usuarioId ? (int) $usuarioId : null, $motivo);
            }

            return response()->json(['message' => 'Reservas canceladas com sucesso.']);
        });
    }
}
