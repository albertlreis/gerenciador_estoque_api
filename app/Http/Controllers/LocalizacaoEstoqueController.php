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
 * Gerencia a localização física de itens no depósito, como corredor, prateleira, etc.
 */
class LocalizacaoEstoqueController extends Controller
{
    /**
     * @var LocalizacaoEstoqueService
     */
    protected LocalizacaoEstoqueService $service;

    /**
     * Construtor com injeção de dependência do service.
     *
     * @param LocalizacaoEstoqueService $service
     */
    public function __construct(LocalizacaoEstoqueService $service)
    {
        $this->service = $service;
    }

    /**
     * Lista paginada das localizações de estoque.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $localizacoes = $this->service->listar();
        return response()->json(LocalizacaoEstoqueResource::collection($localizacoes));
    }

    /**
     * Cria uma localização para um ‘item’ de estoque.
     *
     * @param StoreLocalizacaoEstoqueRequest $request
     * @return JsonResponse
     */
    public function store(StoreLocalizacaoEstoqueRequest $request): JsonResponse
    {
        $localizacao = $this->service->criar($request->validated());
        return response()->json(new LocalizacaoEstoqueResource($localizacao), 201);
    }

    /**
     * Retorna os detalhes de uma localização de estoque específica.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $localizacao = $this->service->visualizar($id);
        return response()->json(new LocalizacaoEstoqueResource($localizacao));
    }

    /**
     * Atualiza uma localização de estoque existente.
     *
     * @param UpdateLocalizacaoEstoqueRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateLocalizacaoEstoqueRequest $request, int $id): JsonResponse
    {
        $localizacao = $this->service->atualizar($id, $request->validated());
        return response()->json(new LocalizacaoEstoqueResource($localizacao));
    }

    /**
     * Remove uma localização de estoque.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $this->service->excluir($id);
        return response()->json(null, 204);
    }
}
