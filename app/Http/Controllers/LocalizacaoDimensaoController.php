<?php

namespace App\Http\Controllers;

use App\Models\LocalizacaoDimensao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Dimensões de Localização
 * Tipos de dimensão (corredor, prateleira, etc.) gerenciáveis.
 */
class LocalizacaoDimensaoController extends Controller
{
    public function index(): JsonResponse
    {
        $dims = LocalizacaoDimensao::where('ativo', true)
            ->orderBy('ordem')
            ->orderBy('nome')
            ->get();

        return response()->json([
            'data' => $dims,
            'meta' => ['total' => $dims->count()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nome' => 'required|string|max:40|unique:localizacao_dimensoes,nome',
            'placeholder' => 'nullable|string|max:80',
            'ordem' => 'nullable|integer|min:0',
            'ativo' => 'nullable|boolean',
        ]);

        $dim = LocalizacaoDimensao::create([
            'nome' => $data['nome'],
            'placeholder' => $data['placeholder'] ?? null,
            'ordem' => $data['ordem'] ?? 0,
            'ativo' => $data['ativo'] ?? true,
        ]);

        return response()->json(['data' => $dim], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $dim = LocalizacaoDimensao::findOrFail($id);

        $data = $request->validate([
            'nome' => 'required|string|max:40|unique:localizacao_dimensoes,nome,' . $dim->id,
            'placeholder' => 'nullable|string|max:80',
            'ordem' => 'nullable|integer|min:0',
            'ativo' => 'nullable|boolean',
        ]);

        $dim->update($data);

        return response()->json(['data' => $dim]);
    }

    public function destroy(int $id): JsonResponse
    {
        $dim = LocalizacaoDimensao::findOrFail($id);
        $dim->delete();

        return response()->json(null, 204);
    }
}
