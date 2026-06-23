<?php

namespace App\Http\Controllers;

use App\Http\Requests\Financeiro\ConciliacaoBancariaCandidatoRequest;
use App\Http\Requests\Financeiro\ConciliacaoBancariaConfirmarImportacaoRequest;
use App\Http\Requests\Financeiro\ConciliacaoBancariaConfirmarTransacaoRequest;
use App\Http\Requests\Financeiro\ConciliacaoBancariaOfxRequest;
use App\Http\Resources\ConciliacaoBancariaImportacaoResource;
use App\Http\Resources\ConciliacaoBancariaTransacaoResource;
use App\Models\ConciliacaoBancariaImportacao;
use App\Models\ConciliacaoBancariaTransacao;
use App\Services\ConciliacaoBancaria\ConciliacaoBancariaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConciliacaoBancariaController extends Controller
{
    public function __construct(
        private readonly ConciliacaoBancariaService $service,
    ) {}

    public function importarOfx(ConciliacaoBancariaOfxRequest $request): JsonResponse
    {
        $importacao = $this->service->importarOfx(
            $request->file('arquivo'),
            (int) $request->validated('conta_financeira_id')
        );

        return response()->json([
            'data' => new ConciliacaoBancariaImportacaoResource($importacao),
        ], 201);
    }

    public function showImportacao(ConciliacaoBancariaImportacao $importacao): JsonResponse
    {
        $importacao->load([
            'contaFinanceira',
            'transacoes' => fn ($q) => $q->orderBy('data_movimento')->orderBy('id'),
        ]);

        return response()->json([
            'data' => new ConciliacaoBancariaImportacaoResource($importacao),
        ]);
    }

    public function transacoes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'importacao_id' => ['nullable', 'integer', 'exists:conciliacao_bancaria_importacoes,id'],
            'conta_financeira_id' => ['nullable', 'integer', 'exists:contas_financeiras,id'],
            'status' => ['nullable', 'in:sugerido,pendente,conflito,conciliado'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return ConciliacaoBancariaTransacaoResource::collection(
            $this->service->listarTransacoes($validated)
        )->response();
    }

    public function definirCandidato(
        ConciliacaoBancariaCandidatoRequest $request,
        ConciliacaoBancariaTransacao $transacao
    ): JsonResponse {
        $data = $request->validated();
        $transacao = $this->service->definirCandidato(
            $transacao,
            $data['candidato_tipo'] ?? null,
            isset($data['candidato_id']) ? (int) $data['candidato_id'] : null,
            $data['forma_pagamento'] ?? null
        );

        return response()->json([
            'data' => new ConciliacaoBancariaTransacaoResource($transacao),
        ]);
    }

    public function confirmarTransacao(
        ConciliacaoBancariaConfirmarTransacaoRequest $request,
        ConciliacaoBancariaTransacao $transacao
    ): JsonResponse {
        $transacao = $this->service->confirmarTransacao(
            $transacao,
            $request->validated('forma_pagamento')
        );

        return response()->json([
            'data' => new ConciliacaoBancariaTransacaoResource($transacao),
        ]);
    }

    public function confirmarImportacao(
        ConciliacaoBancariaConfirmarImportacaoRequest $request,
        ConciliacaoBancariaImportacao $importacao
    ): JsonResponse {
        $data = $request->validated();
        $resultado = $this->service->confirmarImportacao(
            $importacao,
            $data['transacao_ids'] ?? null,
            $data['forma_pagamento'] ?? null
        );

        $importacao->refresh()->load([
            'contaFinanceira',
            'transacoes' => fn ($q) => $q->orderBy('data_movimento')->orderBy('id'),
        ]);

        return response()->json([
            'data' => new ConciliacaoBancariaImportacaoResource($importacao),
            'resultado' => $resultado,
        ]);
    }

    public function reanalisarImportacao(ConciliacaoBancariaImportacao $importacao): JsonResponse
    {
        $importacao = $this->service->reanalisarImportacao($importacao);

        return response()->json([
            'data' => new ConciliacaoBancariaImportacaoResource($importacao),
        ]);
    }
}
