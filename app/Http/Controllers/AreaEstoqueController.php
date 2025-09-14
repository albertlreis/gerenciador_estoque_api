<?php

namespace App\Http\Controllers;

use App\Models\AreaEstoque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Áreas de Estoque
 * CRUD simples para áreas (Assistência, Devolução, etc.).
 */
class AreaEstoqueController extends Controller
{
    public function index(): JsonResponse
    {
        $areas = AreaEstoque::orderBy('nome')->get();

        return response()->json([
            'data' => $areas,
            'meta' => ['total' => $areas->count()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nome' => 'required|string|max:60|unique:areas_estoque,nome',
            'descricao' => 'nullable|string',
        ]);

        $area = AreaEstoque::create($data);

        return response()->json(['data' => $area], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $area = AreaEstoque::findOrFail($id);

        $data = $request->validate([
            'nome' => 'required|string|max:60|unique:areas_estoque,nome,' . $area->id,
            'descricao' => 'nullable|string',
        ]);

        $area->update($data);

        return response()->json(['data' => $area]);
    }

    public function destroy(int $id): JsonResponse
    {
        $area = AreaEstoque::findOrFail($id);
        $area->delete();

        return response()->json(null, 204);
    }
}
