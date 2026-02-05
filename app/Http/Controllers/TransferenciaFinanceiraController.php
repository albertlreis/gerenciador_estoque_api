<?php

namespace App\Http\Controllers;

use App\Http\Requests\Financeiro\TransferenciaFinanceiraIndexRequest;
use App\Http\Requests\Financeiro\TransferenciaFinanceiraStoreRequest;
use App\Http\Requests\Financeiro\TransferenciaFinanceiraUpdateRequest;
use App\Models\TransferenciaFinanceira;
use App\Services\TransferenciaFinanceiraService;
use Illuminate\Http\JsonResponse;

class TransferenciaFinanceiraController extends Controller
{
    public function __construct(
        protected TransferenciaFinanceiraService $service
    ) {}

    public function index(TransferenciaFinanceiraIndexRequest $request): JsonResponse
    {
        $f = $request->validated();

        $q = TransferenciaFinanceira::query()
            ->with([
                'contaOrigem:id,nome,moeda,tipo',
                'contaDestino:id,nome,moeda,tipo',
                // se quiser mostrar os 2 lançamentos no index, descomente:
                // 'lancamentos:id,referencia_id,referencia_type,descricao,tipo,status,conta_id,valor,data_movimento',
            ])
            ->when(!empty($f['data_inicio']), fn ($qq) => $qq->whereDate('data_movimento', '>=', $f['data_inicio']))
            ->when(!empty($f['data_fim']), fn ($qq) => $qq->whereDate('data_movimento', '<=', $f['data_fim']))
            ->when(!empty($f['status']), fn ($qq) => $qq->where('status', $f['status']))
            ->when(!empty($f['conta_id']), function ($qq) use ($f) {
                $qq->where(function ($w) use ($f) {
                    $w->where('conta_origem_id', $f['conta_id'])
                        ->orWhere('conta_destino_id', $f['conta_id']);
                });
            })
            ->when(!empty($f['q']), function ($qq) use ($f) {
                $term = trim((string) $f['q']);
                // Na tabela nova não existe "descricao"; usamos observacoes como busca textual.
                $qq->where('observacoes', 'like', "%{$term}%");
            })
            ->orderByDesc('data_movimento')
            ->orderByDesc('id');

        $items = $q->get()->map(function (TransferenciaFinanceira $t) {
            return [
                'id' => (int) $t->id,
                'status' => (string) $t->status,
                'valor' => (string) $t->valor,
                'data_movimento' => optional($t->data_movimento)->toISOString(),
                'observacoes' => $t->observacoes,

                'conta_origem_id' => (int) $t->conta_origem_id,
                'conta_destino_id' => (int) $t->conta_destino_id,

                'conta_origem' => $t->contaOrigem ? [
                    'id' => (int) $t->contaOrigem->id,
                    'nome' => (string) $t->contaOrigem->nome,
                    'moeda' => (string) $t->contaOrigem->moeda,
                    'tipo' => (string) $t->contaOrigem->tipo,
                ] : null,

                'conta_destino' => $t->contaDestino ? [
                    'id' => (int) $t->contaDestino->id,
                    'nome' => (string) $t->contaDestino->nome,
                    'moeda' => (string) $t->contaDestino->moeda,
                    'tipo' => (string) $t->contaDestino->tipo,
                ] : null,
            ];
        });

        return response()->json(['data' => $items]);
    }

    public function show(TransferenciaFinanceira $transferencia): JsonResponse
    {
        $transferencia->load([
            'contaOrigem:id,nome,moeda,tipo',
            'contaDestino:id,nome,moeda,tipo',
            'lancamentos:id,referencia_id,referencia_type,descricao,tipo,status,conta_id,valor,data_movimento',
        ]);

        return response()->json([
            'data' => [
                'id' => (int) $transferencia->id,
                'status' => (string) $transferencia->status,
                'valor' => (string) $transferencia->valor,
                'data_movimento' => optional($transferencia->data_movimento)->toISOString(),
                'observacoes' => $transferencia->observacoes,

                'conta_origem_id' => (int) $transferencia->conta_origem_id,
                'conta_destino_id' => (int) $transferencia->conta_destino_id,

                'conta_origem' => $transferencia->contaOrigem ? [
                    'id' => (int) $transferencia->contaOrigem->id,
                    'nome' => (string) $transferencia->contaOrigem->nome,
                    'moeda' => (string) $transferencia->contaOrigem->moeda,
                    'tipo' => (string) $transferencia->contaOrigem->tipo,
                ] : null,

                'conta_destino' => $transferencia->contaDestino ? [
                    'id' => (int) $transferencia->contaDestino->id,
                    'nome' => (string) $transferencia->contaDestino->nome,
                    'moeda' => (string) $transferencia->contaDestino->moeda,
                    'tipo' => (string) $transferencia->contaDestino->tipo,
                ] : null,

                'lancamentos' => $transferencia->lancamentos->map(fn ($l) => [
                    'id' => (int) $l->id,
                    'descricao' => (string) $l->descricao,
                    'tipo' => (string) ($l->tipo?->value ?? $l->tipo),
                    'status' => (string) ($l->status?->value ?? $l->status),
                    'conta_id' => (int) $l->conta_id,
                    'valor' => (string) $l->valor,
                    'data_movimento' => optional($l->data_movimento)->toISOString(),
                ])->values(),
            ],
        ]);
    }

    public function store(TransferenciaFinanceiraStoreRequest $request): JsonResponse
    {
        $model = $this->service->criar($request->validated());

        return response()->json([
            'message' => 'Transferência criada com sucesso.',
            'data' => [
                'id' => (int) $model->id,
            ],
        ], 201);
    }

    public function update(
        TransferenciaFinanceiraUpdateRequest $request,
        TransferenciaFinanceira $transferencia
    ): JsonResponse {
        $model = $this->service->atualizar($transferencia, $request->validated());

        return response()->json([
            'message' => 'Transferência atualizada com sucesso.',
            'data' => ['id' => (int) $model->id],
        ]);
    }

    /**
     * Importante: em "extrato" a remoção ideal é CANCELAR (mantém histórico)
     * e cancela os 2 lançamentos.
     */
    public function destroy(TransferenciaFinanceira $transferencia): JsonResponse
    {
        $this->service->cancelar($transferencia);

        return response()->json([
            'message' => 'Transferência cancelada com sucesso.',
        ]);
    }
}
