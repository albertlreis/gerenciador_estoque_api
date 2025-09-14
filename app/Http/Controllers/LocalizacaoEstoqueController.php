<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLocalizacaoEstoqueRequest;
use App\Http\Requests\UpdateLocalizacaoEstoqueRequest;
use App\Http\Resources\LocalizacaoEstoqueResource;
use App\Services\LocalizacaoEstoqueService;
use Illuminate\Http\JsonResponse;

/**
 * @group Localizações de Estoque
 *
 * Gerencia a localização física de itens no depósito.
 */
class LocalizacaoEstoqueController extends Controller
{
    public function __construct(protected LocalizacaoEstoqueService $service) {}

    /**
     * Lista paginada das localizações de estoque.
     */
    public function index(): JsonResponse
    {
        $localizacoes = $this->service->listar();
        return response()->json(LocalizacaoEstoqueResource::collection($localizacoes)->additional([
            'meta' => [
                'current_page' => $localizacoes->currentPage(),
                'per_page'     => $localizacoes->perPage(),
                'total'        => $localizacoes->total(),
                'last_page'    => $localizacoes->lastPage(),
            ],
        ]));
    }

    /**
     * Cria uma localização para um item de estoque.
     */
    public function store(StoreLocalizacaoEstoqueRequest $request): JsonResponse
    {
        $localizacao = $this->service->criar($request->validated());
        return response()->json(new LocalizacaoEstoqueResource($localizacao->load(['area', 'valores.dimensao'])), 201);
    }

    /**
     * Detalhes de uma localização específica.
     */
    public function show(int $id): JsonResponse
    {
        $localizacao = $this->service->visualizar($id);
        return response()->json(new LocalizacaoEstoqueResource($localizacao->load(['area', 'valores.dimensao'])));
    }

    /**
     * Atualiza uma localização de estoque.
     */
    public function update(UpdateLocalizacaoEstoqueRequest $request, int $id): JsonResponse
    {
        $localizacao = $this->service->atualizar($id, $request->validated());
        return response()->json(new LocalizacaoEstoqueResource($localizacao->load(['area', 'valores.dimensao'])));
    }

    /**
     * Remove uma localização.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->service->excluir($id);
        return response()->json(null, 204);
    }
}
