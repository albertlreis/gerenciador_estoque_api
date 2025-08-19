<?php

namespace App\Http\Controllers\Assistencia;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssistenciaDefeitoResource;
use App\Models\AssistenciaDefeito;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistenciaDefeitosController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = AssistenciaDefeito::query();

        if ($s = $request->input('busca')) {
            $q->where(function ($w) use ($s) {
                $w->where('codigo', 'like', "%{$s}%")
                    ->orWhere('descricao', 'like', "%{$s}%");
            });
        }

        if ($request->filled('ativo')) {
            $q->where('ativo', (bool) $request->boolean('ativo'));
        }

        $perPage = (int) $request->input('per_page', 15);
        $data = $q->orderBy('codigo')->paginate($perPage);

        return AssistenciaDefeitoResource::collection($data)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:50', 'unique:assistencia_defeitos,codigo'],
            'descricao' => ['required', 'string', 'max:255'],
            'critico' => ['nullable', 'boolean'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        $defeito = AssistenciaDefeito::create($data);

        return (new AssistenciaDefeitoResource($defeito))->response();
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $defeito = AssistenciaDefeito::findOrFail($id);

        $data = $request->validate([
            'codigo' => ['sometimes', 'string', 'max:50', "unique:assistencia_defeitos,codigo,{$id}"],
            'descricao' => ['sometimes', 'string', 'max:255'],
            'critico' => ['sometimes', 'boolean'],
            'ativo' => ['sometimes', 'boolean'],
        ]);

        $defeito->update($data);

        return (new AssistenciaDefeitoResource($defeito))->response();
    }

    public function destroy(int $id): JsonResponse
    {
        $defeito = AssistenciaDefeito::findOrFail($id);
        $defeito->delete();

        return response()->json(['message' => 'Defeito removido.']);
    }
}
