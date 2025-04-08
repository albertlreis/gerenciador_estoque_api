<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Models\PedidoItem;
use Illuminate\Http\Request;

class PedidoItemController extends Controller
{
    public function index(Pedido $pedido)
    {
        return response()->json($pedido->itens);
    }

    public function store(Request $request, Pedido $pedido)
    {
        $validated = $request->validate([
            'id_variacao'    => 'required|exists:produto_variacoes,id',
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
}
