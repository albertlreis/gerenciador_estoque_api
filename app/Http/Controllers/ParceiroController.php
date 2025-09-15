<?php

namespace App\Http\Controllers;

use App\Http\Requests\ParceiroIndexRequest;
use App\Http\Requests\ParceiroStoreRequest;
use App\Http\Requests\ParceiroUpdateRequest;
use App\Http\Resources\ParceiroResource;
use App\Services\ParceiroService;
use Illuminate\Http\JsonResponse;

class ParceiroController extends Controller
{
    public function __construct(private readonly ParceiroService $service) {}

    /** GET /parceiros */
    public function index(ParceiroIndexRequest $request): JsonResponse
    {
        $paginado = $this->service->listar($request->validated());

        return ParceiroResource::collection($paginado)
            ->additional([
                'meta' => [
                    'current_page' => $paginado->currentPage(),
                    'per_page'     => $paginado->perPage(),
                    'total'        => $paginado->total(),
                    'last_page'    => $paginado->lastPage(),
                ]
            ])->response();
    }

    /** GET /parceiros/{id} */
    public function show(int $id): JsonResponse
    {
        $parceiro = $this->service->obter($id);
        return response()->json(new ParceiroResource($parceiro));
    }

    /** POST /parceiros */
    public function store(ParceiroStoreRequest $request): JsonResponse
    {
        $parceiro = $this->service->criar($request->validated());

        return response()->json([
            'sucesso'  => true,
            'mensagem' => 'Parceiro criado com sucesso.',
            'data'     => new ParceiroResource($parceiro),
        ], 201);
    }

    /** PUT /parceiros/{id} */
    public function update(ParceiroUpdateRequest $request, int $id): JsonResponse
    {
        $parceiro = $this->service->atualizar($id, $request->validated());

        return response()->json([
            'sucesso'  => true,
            'mensagem' => 'Parceiro atualizado com sucesso.',
            'data'     => new ParceiroResource($parceiro),
        ]);
    }

    /** DELETE /parceiros/{id} (soft delete) */
    public function destroy(int $id): JsonResponse
    {
        $this->service->remover($id);

        return response()->json([
            'sucesso'  => true,
            'mensagem' => 'Parceiro removido (soft delete).'
        ]);
    }

    /** POST /parceiros/{id}/restore */
    public function restore(int $id): JsonResponse
    {
        $parceiro = $this->service->restaurar($id);

        return response()->json([
            'sucesso'  => true,
            'mensagem' => 'Parceiro restaurado com sucesso.',
            'data'     => new ParceiroResource($parceiro),
        ]);
    }
}
