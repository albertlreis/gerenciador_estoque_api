<?php

namespace App\Http\Controllers;

use App\Http\Requests\Financeiro\ExecutarDespesaRecorrenteRequest;
use App\Http\Requests\Financeiro\StoreDespesaRecorrenteRequest;
use App\Http\Requests\Financeiro\UpdateDespesaRecorrenteRequest;
use App\Http\Resources\DespesaRecorrenteCollection;
use App\Http\Resources\DespesaRecorrenteShowResource;
use App\Models\DespesaRecorrente;
use App\Services\DespesaRecorrenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DespesaRecorrenteController extends Controller
{
    public function __construct(protected DespesaRecorrenteService $service) {}

    public function index(Request $request): JsonResponse
    {
        $p = $this->service->listar($request->all());
        return response()->json(new DespesaRecorrenteCollection($p));
    }

    public function show(int $id): JsonResponse
    {
        $model = DespesaRecorrente::with(['fornecedor','usuario','execucoes'])->findOrFail($id);
        return response()->json(new DespesaRecorrenteShowResource($model));
    }

    public function store(StoreDespesaRecorrenteRequest $request): JsonResponse
    {
        $usuarioId = (int) ($request->user()?->id ?? 0);
        $model = $this->service->criar($request->validated(), $usuarioId);
        return response()->json($model, 201);
    }

    public function update(UpdateDespesaRecorrenteRequest $request, int $id): JsonResponse
    {
        $model = $this->service->atualizar($id, $request->validated());
        return response()->json($model);
    }

    public function pause(int $id): JsonResponse
    {
        $model = $this->service->pausar($id);
        return response()->json($model);
    }

    public function activate(int $id): JsonResponse
    {
        $model = $this->service->ativar($id);
        return response()->json($model);
    }

    public function cancel(int $id): JsonResponse
    {
        $model = $this->service->cancelar($id);
        return response()->json($model);
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function executar(ExecutarDespesaRecorrenteRequest $request, int $id): JsonResponse
    {
        $result = $this->service->executarManual($id, $request->validated());
        return response()->json($result);
    }
}
