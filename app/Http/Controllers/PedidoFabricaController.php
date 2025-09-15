<?php

namespace App\Http\Controllers;

use App\Http\Requests\PedidoFabricaEntregaParcialRequest;
use App\Http\Requests\PedidoFabricaIndexRequest;
use App\Http\Requests\PedidoFabricaStoreRequest;
use App\Http\Requests\PedidoFabricaUpdateRequest;
use App\Http\Requests\PedidoFabricaUpdateStatusRequest;
use App\Http\Resources\PedidoFabricaResource;
use App\Models\PedidoFabrica;
use App\Services\PedidoFabricaService;
use Illuminate\Http\JsonResponse;

/**
 * Controller de Pedidos de Fábrica.
 */
class PedidoFabricaController extends Controller
{
    public function __construct(private readonly PedidoFabricaService $service) {}

    /**
     * Lista com filtro opcional por status.
     */
    public function index(PedidoFabricaIndexRequest $request): JsonResponse
    {
        $query = PedidoFabrica::with(['itens.variacao.produto'])
            ->when($request->validated('status'), fn($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at');

        return PedidoFabricaResource::collection($query->get())->response();
    }

    /**
     * Cria pedido + itens.
     */
    public function store(PedidoFabricaStoreRequest $request): JsonResponse
    {
        $pedido = $this->service->criar($request);

        return (new PedidoFabricaResource($pedido->load(['itens.variacao.produto', 'historicos'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Exibe um pedido completo.
     */
    public function show(int $id): JsonResponse
    {
        $pedido = PedidoFabrica::with([
            'itens.variacao.produto',
            'itens.variacao.atributos',
            'itens.pedidoVenda.cliente',
            'historicos',
            'entregas.deposito',
        ])->findOrFail($id);

        return (new PedidoFabricaResource($pedido))->response();
    }


    /**
     * Atualiza dados e itens do pedido (substitui os itens).
     */
    public function update(PedidoFabricaUpdateRequest $request, int $id): JsonResponse
    {
        $pedido = $this->service->atualizar($request, $id);

        return (new PedidoFabricaResource($pedido))->response();
    }

    /**
     * Atualiza o status manualmente e registra histórico + responsável.
     */
    public function updateStatus(PedidoFabricaUpdateStatusRequest $request, int $id): JsonResponse
    {
        $pedido = $this->service->atualizarStatus($request, $id);

        return (new PedidoFabricaResource($pedido))->response();
    }

    /**
     * Registra entrega (parcial ou total) para um item.
     */
    public function registrarEntrega(PedidoFabricaEntregaParcialRequest $request, int $itemId): JsonResponse
    {
        $pedido = $this->service->registrarEntregaParcial($request, $itemId);

        return (new PedidoFabricaResource($pedido))->response();
    }

    /**
     * Remove pedido (apenas pendente).
     */
    public function destroy(int $id): JsonResponse
    {
        $pedido = PedidoFabrica::findOrFail($id);

        if ($pedido->status !== 'pendente') {
            return response()->json(['error' => 'Só é possível excluir pedidos pendentes.'], 422);
        }

        $pedido->delete();

        return response()->json(['message' => 'Pedido removido com sucesso.']);
    }
}
