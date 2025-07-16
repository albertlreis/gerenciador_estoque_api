<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Models\PedidoItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PedidoItemController extends Controller
{
    public function index(Pedido $pedido)
    {
        return response()->json($pedido->itens);
    }

    public function store(Request $request, Pedido $pedido)
    {
        $validated = $request->validate([
            'id_produto'     => 'required|exists:produtos,id',
            'quantidade'     => 'required|integer',
            'preco_unitario' => 'required|numeric',
        ]);

        $validated['id_pedido'] = $pedido->id;
        $item = PedidoItem::create($validated);
        return response()->json($item, 201);
    }

    public function show(Pedido $pedido, PedidoItem $item)
    {
        if ($item->id_pedido !== $pedido->id) {
            return response()->json(['error' => 'Item não pertence a este pedido'], 404);
        }
        return response()->json($item);
    }

    public function update(Request $request, Pedido $pedido, PedidoItem $item)
    {
        if ($item->id_pedido !== $pedido->id) {
            return response()->json(['error' => 'Item não pertence a este pedido'], 404);
        }

        $validated = $request->validate([
            'id_produto'     => 'sometimes|required|integer',
            'quantidade'     => 'sometimes|required|integer',
            'preco_unitario' => 'sometimes|required|numeric',
        ]);

        $item->update($validated);
        return response()->json($item);
    }

    public function destroy(Pedido $pedido, PedidoItem $item)
    {
        if ($item->id_pedido !== $pedido->id) {
            return response()->json(['error' => 'Item não pertence a este pedido'], 404);
        }

        $item->delete();
        return response()->json(null, 204);
    }

    /**
     * Libera a entrega de um item pendente, marcando a data e disparando movimentação (futura).
     */
    public function liberarEntrega(int $id, Request $request): JsonResponse
    {
        $item = PedidoItem::findOrFail($id);

        if (!$item->is_entrega_pendente) {
            return response()->json(['message' => 'Item não está pendente de entrega.'], 400);
        }

        $item->data_liberacao_entrega = Carbon::now();
        $item->observacao_entrega_pendente = $request->input('observacao') ?? null;
        $item->save();

        return response()->json(['message' => 'Entrega liberada com sucesso.']);
    }

    /**
     * Lista global de itens de pedidos, com opção de filtro por entrega pendente.
     */
    public function indexGlobal(Request $request): JsonResponse
    {
        $query = PedidoItem::with([
            'pedido.cliente',
            'variacao.produto',
            'variacao.atributos'
        ]);

        if ($request->boolean('entrega_pendente')) {
            $query->where('entrega_pendente', true)
                ->whereNull('data_liberacao_entrega');
        }

        return response()->json($query->get());
    }
}
