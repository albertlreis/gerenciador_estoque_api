<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoriaIndexRequest;
use App\Http\Requests\CategoriaStoreRequest;
use App\Http\Requests\CategoriaUpdateRequest;
use App\Models\Categoria;
use App\Services\CategoriaService;
use Illuminate\Http\JsonResponse;

class CategoriaController extends Controller
{
    public function __construct(
        private readonly CategoriaService $service
    ) {}

    public function index(CategoriaIndexRequest $request): JsonResponse
    {
        $search = $request->validated()['search'] ?? null;

        $items = $this->service->listar($search);

        return response()->json($items);
    }

    public function store(CategoriaStoreRequest $request): JsonResponse
    {
        /** @var array{nome:string,descricao?:string|null,categoria_pai_id?:int|null} $data */
        $data = $request->validated();

        $categoria = $this->service->criar($data);

        return response()->json([
            'id'    => $categoria->id,
            'nome'  => $categoria->nome,
            'label' => $categoria->nome,
            'value' => $categoria->id,
        ], 201);
    }

    public function show(Categoria $categoria): JsonResponse
    {
        return response()->json($categoria);
    }

    public function update(CategoriaUpdateRequest $request, Categoria $categoria): JsonResponse
    {
        /** @var array{nome?:string,descricao?:string|null,categoria_pai_id?:int|null} $data */
        $data = $request->validated();

        $categoria = $this->service->atualizar($categoria, $data);

        return response()->json($categoria);
    }

    public function destroy(Categoria $categoria): JsonResponse
    {
        $this->service->remover($categoria);

        return response()->json(null, 204);
    }
}
