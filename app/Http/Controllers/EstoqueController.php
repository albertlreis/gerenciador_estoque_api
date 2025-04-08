<?php

namespace App\Http\Controllers;

use App\Models\Deposito;
use App\Models\Estoque;
use Illuminate\Http\Request;

class EstoqueController extends Controller
{
    public function index(Deposito $deposito)
    {
        return response()->json($deposito->estoque);
    }

    public function store(Request $request, Deposito $deposito)
    {
        $validated = $request->validate([
            'id_variacao' => 'required|exists:produto_variacoes,id',
            'quantidade'  => 'required|integer',
        ]);

        $validated['id_deposito'] = $deposito->id;
        $estoque = Estoque::create($validated);
        return response()->json($estoque, 201);
    }

    public function show(Deposito $deposito, Estoque $estoque)
    {
        if ($estoque->id_deposito !== $deposito->id) {
            return response()->json(['error' => 'Registro de estoque não pertence a este depósito'], 404);
        }
        return response()->json($estoque);
    }

    public function update(Request $request, Deposito $deposito, Estoque $estoque)
    {
        if ($estoque->id_deposito !== $deposito->id) {
            return response()->json(['error' => 'Registro de estoque não pertence a este depósito'], 404);
        }

        $validated = $request->validate([
            'quantidade' => 'sometimes|required|integer',
        ]);

        $estoque->update($validated);
        return response()->json($estoque);
    }

    public function destroy(Deposito $deposito, Estoque $estoque)
    {
        if ($estoque->id_deposito !== $deposito->id) {
            return response()->json(['error' => 'Registro de estoque não pertence a este depósito'], 404);
        }

        $estoque->delete();
        return response()->json(null, 204);
    }
}
