<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\Produto;
use Illuminate\Http\Request;

class ProdutoController extends Controller
{
    public function index(Categoria $categoria)
    {
        return response()->json($categoria->produtos);
    }

    public function store(Request $request, Categoria $categoria)
    {
        $validated = $request->validate([
            'nome'      => 'required|string|max:255',
            'descricao' => 'nullable|string',
            'ativo'     => 'boolean',
        ]);

        // Força o relacionamento com a categoria pai
        $validated['id_categoria'] = $categoria->id;

        $produto = Produto::create($validated);
        return response()->json($produto, 201);
    }

    public function show(Categoria $categoria, Produto $produto)
    {
        if ($produto->id_categoria !== $categoria->id) {
            return response()->json(['error' => 'Produto não pertence a esta categoria'], 404);
        }
        return response()->json($produto);
    }

    public function update(Request $request, Categoria $categoria, Produto $produto)
    {
        if ($produto->id_categoria !== $categoria->id) {
            return response()->json(['error' => 'Produto não pertence a esta categoria'], 404);
        }

        $validated = $request->validate([
            'nome'      => 'sometimes|required|string|max:255',
            'descricao' => 'nullable|string',
            'ativo'     => 'boolean',
        ]);

        $produto->update($validated);
        return response()->json($produto);
    }

    public function destroy(Categoria $categoria, Produto $produto)
    {
        if ($produto->id_categoria !== $categoria->id) {
            return response()->json(['error' => 'Produto não pertence a esta categoria'], 404);
        }

        $produto->delete();
        return response()->json(null, 204);
    }
}
