<?php

namespace App\Http\Controllers\Assistencia;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssistenciaResource;
use App\Models\Assistencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistenciasController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Assistencia::query();

        if ($s = $request->input('busca')) {
            $q->where(function ($w) use ($s) {
                $w->where('nome', 'like', "%{$s}%")
                    ->orWhere('cnpj', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%");
            });
        }

        if ($request->filled('ativo')) {
            $q->where('ativo', (bool) $request->boolean('ativo'));
        }

        $perPage = (int) $request->input('per_page', 15);
        $data = $q->orderBy('nome')->paginate($perPage);

        return AssistenciaResource::collection($data)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'cnpj' => ['nullable', 'string', 'max:20'],
            'telefone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'contato' => ['nullable', 'string', 'max:150'],
            'endereco_json' => ['nullable', 'array'],
            'prazo_padrao_dias' => ['nullable', 'integer', 'min:1', 'max:365'],
            'ativo' => ['nullable', 'boolean'],
            'observacoes' => ['nullable', 'string'],
        ]);

        $assist = Assistencia::create($data);

        return (new AssistenciaResource($assist))->response();
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $assist = Assistencia::findOrFail($id);

        $data = $request->validate([
            'nome' => ['sometimes', 'string', 'max:255'],
            'cnpj' => ['sometimes', 'nullable', 'string', 'max:20'],
            'telefone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'contato' => ['sometimes', 'nullable', 'string', 'max:150'],
            'endereco_json' => ['sometimes', 'nullable', 'array'],
            'prazo_padrao_dias' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'ativo' => ['sometimes', 'boolean'],
            'observacoes' => ['sometimes', 'nullable', 'string'],
        ]);

        $assist->update($data);

        return (new AssistenciaResource($assist))->response();
    }

    public function destroy(int $id): JsonResponse
    {
        $assist = Assistencia::findOrFail($id);
        $assist->delete();

        return response()->json(['message' => 'Assistência removida.']);
    }
}
