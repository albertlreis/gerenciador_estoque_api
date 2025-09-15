<?php

namespace App\Http\Controllers;

use App\Http\Requests\FornecedorIndexRequest;
use App\Http\Requests\FornecedorStoreRequest;
use App\Http\Requests\FornecedorUpdateRequest;
use App\Http\Resources\FornecedorResource;
use App\Services\FornecedorService;
use Illuminate\Http\JsonResponse;

/**
 * Controller REST de Fornecedores.
 *
 * @group Administração
 */
class FornecedorController extends Controller
{
    public function __construct(private readonly FornecedorService $service) {}

    /**
     * GET /fornecedores
     * Lista com filtros e paginação.
     */
    public function index(FornecedorIndexRequest $request): JsonResponse
    {
        $paginado = $this->service->listar($request->validated());

        return FornecedorResource::collection($paginado)
            ->additional([
                'meta' => [
                    'current_page' => $paginado->currentPage(),
                    'per_page'     => $paginado->perPage(),
                    'total'        => $paginado->total(),
                    'last_page'    => $paginado->lastPage(),
                ]
            ])->response();
    }

    /**
     * GET /fornecedores/{id}
     * Detalhes de um fornecedor.
     */
    public function show(int $id): JsonResponse
    {
        $fornecedor = $this->service->obter($id);
        return response()->json(new FornecedorResource($fornecedor));
    }

    /**
     * POST /fornecedores
     * Cria fornecedor.
     */
    public function store(FornecedorStoreRequest $request): JsonResponse
    {
        $fornecedor = $this->service->criar($request->validated());

        return response()->json([
            'sucesso'  => true,
            'mensagem' => 'Fornecedor criado com sucesso.',
            'data'     => new FornecedorResource($fornecedor),
        ], 201);
    }

    /**
     * PUT /fornecedores/{id}
     * Atualiza fornecedor.
     */
    public function update(FornecedorUpdateRequest $request, int $id): JsonResponse
    {
        $fornecedor = $this->service->atualizar($id, $request->validated());

        return response()->json([
            'sucesso'  => true,
            'mensagem' => 'Fornecedor atualizado com sucesso.',
            'data'     => new FornecedorResource($fornecedor),
        ]);
    }

    /**
     * DELETE /fornecedores/{id}
     * Soft delete.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->service->remover($id);

        return response()->json([
            'sucesso'  => true,
            'mensagem' => 'Fornecedor removido (soft delete).'
        ]);
    }

    /**
     * POST /fornecedores/{id}/restore
     * Restaura fornecedor removido.
     */
    public function restore(int $id): JsonResponse
    {
        $fornecedor = $this->service->restaurar($id);

        return response()->json([
            'sucesso'  => true,
            'mensagem' => 'Fornecedor restaurado com sucesso.',
            'data'     => new FornecedorResource($fornecedor),
        ]);
    }

    /**
     * GET /fornecedores/{id}/produtos
     * Lista produtos vinculados.
     */
    public function produtos(int $id): JsonResponse
    {
        $perPage  = (int) request('per_page', 20);
        $forn     = $this->service->obter($id);
        $produtos = $this->service->listarProdutos($id, $perPage);

        return response()->json([
            'fornecedor' => new FornecedorResource($forn),
            'produtos'   => $produtos,
        ]);
    }
}
