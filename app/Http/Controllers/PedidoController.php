<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use Illuminate\Http\Request;

class PedidoController extends Controller
{
    public function index()
    {
        return response()->json(Pedido::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_cliente'  => 'required|exists:clientes,id',
            'data_pedido' => 'nullable|date',
            'status'      => 'required|string|max:50',
            'observacoes' => 'nullable|string',
        ]);

        $pedido = Pedido::create($validated);
        return response()->json($pedido, 201);
    }

    public function show(Pedido $pedido)
    {
        return response()->json($pedido);
    }

    public function update(Request $request, Pedido $pedido)
    {
        $validated = $request->validate([
            'id_cliente'  => 'sometimes|required|exists:clientes,id',
            'data_pedido' => 'nullable|date',
            'status'      => 'sometimes|required|string|max:50',
            'observacoes' => 'nullable|string',
        ]);

        $pedido->update($validated);
        return response()->json($pedido);
    }

    public function destroy(Pedido $pedido)
    {
        $pedido->delete();
        return response()->json(null, 204);
    }
}
