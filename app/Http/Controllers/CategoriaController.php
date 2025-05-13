<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\Request;

class CategoriaController extends Controller
{
    public function index()
    {
        return response()->json(Categoria::with('subcategorias')->whereNull('categoria_pai_id')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:255',
            'descricao' => 'nullable|string',
            'categoria_pai_id' => 'nullable|exists:categorias,id',
        ]);

        $categoria = Categoria::create($validated);
        return response()->json($categoria, 201);
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
