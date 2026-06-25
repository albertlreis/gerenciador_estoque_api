<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\Bancos\BancoDoBrasil\BancoDoBrasilExtratosService;
use App\Integrations\Bancos\Exceptions\BancoDoBrasilIntegrationException;
use App\Models\ContaFinanceira;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BancoDoBrasilExtratosController extends Controller
{
    public function __construct(
        private readonly BancoDoBrasilExtratosService $service
    ) {}

    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conta_financeira_id' => ['nullable', 'integer', 'exists:contas_financeiras,id'],
        ]);

        return response()->json($this->service->status(
            isset($validated['conta_financeira_id']) ? (int) $validated['conta_financeira_id'] : null
        ));
    }

    public function testarConexao(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conta_financeira_id' => ['required', 'integer', 'exists:contas_financeiras,id'],
        ]);

        $conta = ContaFinanceira::query()->findOrFail((int) $validated['conta_financeira_id']);

        try {
            $conexao = $this->service->testarConexao($conta);
        } catch (BancoDoBrasilIntegrationException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'conexao' => $this->service->conexaoPayload($conexao),
        ]);
    }
}
