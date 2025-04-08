<?php

namespace App\Http\Controllers;

use App\Models\Produto;
use Illuminate\Http\Request;

class ProdutoController extends Controller
{
    public function index()
    {
        return response()->json(Produto::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome'         => 'required|string|max:255',
            'descricao'    => 'nullable|string',
            'id_categoria' => 'required|exists:categorias,id',
            'ativo'        => 'boolean',
        ]);

        $produto = Produto::create($validated);
        return response()->json($produto, 201);
    }

    public function show(Produto $produto)
    {
        return response()->json($produto);
    }

    public function update(Request $request, Produto $produto)
    {
        $validated = $request->validate([
            'nome'         => 'sometimes|required|string|max:255',
            'descricao'    => 'nullable|string',
            'id_categoria' => 'sometimes|required|exists:categorias,id',
            'ativo'        => 'boolean',
        ]);

        $produto->update($validated);
        return response()->json($produto);
    }

    public function destroy(Produto $produto)
    {
        $produto->delete();
        return response()->json(null, 204);
    }
}
