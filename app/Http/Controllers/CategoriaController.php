<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\Request;

class CategoriaController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->query('search');

        $query = Categoria::with('subcategorias.subcategorias')
            ->orderBy('nome');

        if ($search) {
            $query->where('nome', 'like', "%{$search}%");
        }

        return response()->json($query->get(['id', 'nome', 'categoria_pai_id']));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:255',
            'descricao' => 'nullable|string',
            'categoria_pai_id' => 'nullable|exists:categorias,id',
        ]);

        $categoria = Categoria::create($validated);

        return response()->json([
            'id' => $categoria->id,
            'nome' => $categoria->nome,
            'label' => $categoria->nome,
            'value' => $categoria->id,
        ], 201);
    }

    public function show(Categoria $categoria)
    {
        return response()->json($categoria);
    }

    public function update(Request $request, Categoria $categoria)
    {
        $validated = $request->validate([
            'nome' => 'sometimes|required|string|max:255',
            'descricao' => 'nullable|string',
            'categoria_pai_id' => 'nullable|exists:categorias,id',
        ]);

        $categoria->update($validated);
        return response()->json($categoria);
    }

    public function destroy(Categoria $categoria)
    {
        $categoria->delete();
        return response()->json(null, 204);
    }
}
