<?php

namespace App\Http\Controllers;

use App\Models\Produto;
use App\Models\ProdutoImagem;
use Illuminate\Http\Request;

class ProdutoImagemController extends Controller
{
    public function index(Produto $produto)
    {
        return response()->json($produto->imagens);
    }

    public function store(Request $request, Produto $produto)
    {
        $validated = $request->validate([
            'url'       => 'required|string',
            'principal' => 'boolean',
        ]);

        $validated['id_produto'] = $produto->id;
        $imagem = ProdutoImagem::create($validated);
        return response()->json($imagem, 201);
    }

    public function show(Produto $produto, ProdutoImagem $imagem)
    {
        if ($imagem->id_produto !== $produto->id) {
            return response()->json(['error' => 'Imagem não pertence a este produto'], 404);
        }
        return response()->json($imagem);
    }

    public function update(Request $request, Produto $produto, ProdutoImagem $imagem)
    {
        if ($imagem->id_produto !== $produto->id) {
            return response()->json(['error' => 'Imagem não pertence a este produto'], 404);
        }

        $validated = $request->validate([
            'url'       => 'sometimes|required|string',
            'principal' => 'boolean',
        ]);

        $imagem->update($validated);
        return response()->json($imagem);
    }

    public function destroy(Produto $produto, ProdutoImagem $imagem)
    {
        if ($imagem->id_produto !== $produto->id) {
            return response()->json(['error' => 'Imagem não pertence a este produto'], 404);
        }

        $imagem->delete();
        return response()->json(null, 204);
    }
}
