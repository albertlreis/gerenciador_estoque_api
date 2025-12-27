<?php

namespace App\Http\Controllers;

use App\DTOs\FiltroLancamentoFinanceiroDTO;
use App\Http\Requests\Financeiro\LancamentoFinanceiroIndexRequest;
use App\Http\Requests\Financeiro\LancamentoFinanceiroStoreRequest;
use App\Http\Requests\Financeiro\LancamentoFinanceiroUpdateRequest;
use App\Http\Resources\LancamentoFinanceiroResource;
use App\Models\LancamentoFinanceiro;
use App\Services\LancamentoFinanceiroService;
use Illuminate\Http\JsonResponse;

class LancamentoFinanceiroController extends Controller
{
    public function __construct(protected LancamentoFinanceiroService $service) {}

    public function index(LancamentoFinanceiroIndexRequest $request): JsonResponse
    {
        $dto = new FiltroLancamentoFinanceiroDTO($request->validated());
        $pag = $this->service->listar($dto);

        return LancamentoFinanceiroResource::collection($pag)
            ->additional([
                'meta' => [
                    'page'      => $pag->currentPage(),
                    'per_page'  => $pag->perPage(),
                    'total'     => $pag->total(),
                    'last_page' => $pag->lastPage(),
                ],
            ])
            ->response();
    }

    public function show(LancamentoFinanceiro $lancamento): JsonResponse
    {
        $lancamento->load(['categoria', 'conta', 'criador', 'centroCusto']);

        return response()->json([
            'data' => new LancamentoFinanceiroResource($lancamento),
        ]);
    }

    public function store(LancamentoFinanceiroStoreRequest $request): JsonResponse
    {
        $model = $this->service->criar($request->validated());

        return response()->json([
            'message' => 'Lançamento criado com sucesso.',
            'data'    => new LancamentoFinanceiroResource($model),
        ], 201);
    }

    public function update(LancamentoFinanceiroUpdateRequest $request, LancamentoFinanceiro $lancamento): JsonResponse
    {
        $model = $this->service->atualizar($lancamento, $request->validated());

        return response()->json([
            'message' => 'Lançamento atualizado com sucesso.',
            'data'    => new LancamentoFinanceiroResource($model),
        ]);
    }

    public function destroy(LancamentoFinanceiro $lancamento): JsonResponse
    {
        $this->service->remover($lancamento);

        return response()->json(['message' => 'Lançamento removido com sucesso.']);
    }

    public function totais(LancamentoFinanceiroIndexRequest $request): JsonResponse
    {
        $dto = new FiltroLancamentoFinanceiroDTO($request->validated());

        return response()->json([
            'data' => $this->service->totais($dto),
        ]);
    }
}
