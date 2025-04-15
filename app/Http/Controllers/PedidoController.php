<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use Illuminate\Http\Request;
use Carbon\Carbon;

class PedidoController extends Controller
{
    public function index()
    {
        $pedidos = Pedido::with('cliente')->get();
        return response()->json($pedidos);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_cliente'  => 'required|exists:clientes,id',
            'data_pedido' => 'nullable|date',
            'status'      => 'required|string|max:50',
            'observacoes' => 'nullable|string',
        ]);

        if (!empty($validated['data_pedido'])) {
            // Converte a data para o formato aceito pelo MySQL
            $validated['data_pedido'] = Carbon::parse($validated['data_pedido'])->format('Y-m-d H:i:s');
        }

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
