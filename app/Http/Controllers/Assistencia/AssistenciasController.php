<?php

namespace App\Http\Controllers\Assistencia;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssistenciaResource;
use App\Models\Assistencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlador responsável pelo CRUD de Assistências Técnicas (autorizadas).
 */
class AssistenciasController extends Controller
{
    /**
     * Lista assistências com paginação e filtros básicos.
     *
     * Filtros aceitos via query string:
     * - busca: string que pesquisa por nome, cnpj ou email (LIKE)
     * - ativo: boolean (true/false)
     * - per_page: inteiro (padrão 15)
     *
     * @param  Request  $request
     * @return JsonResponse
     */
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
            $q->where('ativo', $request->boolean('ativo'));
        }

        $perPage = (int) $request->input('per_page', 15);
        $data = $q->orderBy('nome')->paginate($perPage);

        return AssistenciaResource::collection($data)->response();
    }

    /**
     * Exibe uma assistência específica pelo ID.
     *
     * @param  int  $id  ID da assistência
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $assist = Assistencia::findOrFail($id);

        return (new AssistenciaResource($assist))->response();
    }

    /**
     * Cria uma nova assistência.
     *
     * Campos aceitos no corpo (JSON):
     * - nome: string (obrigatório, máx. 255)
     * - cnpj: string|null (máx. 20)
     * - telefone: string|null (máx. 50)
     * - email: string|null (email, máx. 150)
     * - contato: string|null (máx. 150)
     * - endereco_json: array|null (será persistido como JSON)
     * - prazo_padrao_dias: int|null (1..365)
     * - ativo: bool|null
     * - observacoes: string|null
     *
     * @param  Request  $request
     * @return JsonResponse
     */
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

    /**
     * Atualiza parcialmente uma assistência existente.
     *
     * @param  int      $id       ID da assistência
     * @param  Request  $request  Dados parciais para atualização
     * @return JsonResponse
     */
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

    /**
     * Remove uma assistência definitivamente.
     *
     * @param  int  $id  ID da assistência
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $assist = Assistencia::findOrFail($id);
        $assist->delete();

        return response()->json(['message' => 'Assistência removida.']);
    }
}
