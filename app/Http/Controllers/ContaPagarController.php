<?php

namespace App\Http\Controllers;

use App\DTOs\FiltroContaPagarDTO;
use App\Http\Requests\ContaPagarPagamentoRequest;
use App\Http\Requests\ContaPagarRequest;
use App\Http\Resources\ContaPagarResource;
use App\Models\ContaPagar;
use App\Services\ContaPagarCommandService;
use App\Services\ContaPagarQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContaPagarController extends Controller
{
    public function __construct(
        private readonly ContaPagarQueryService $query,
        private readonly ContaPagarCommandService $cmd,
    ) {
        $this->middleware('auth:sanctum');
        $this->authorizeResource(ContaPagar::class, 'conta_pagar');
    }

    public function index(Request $request)
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
            'data_ini' => 'nullable|date',
            'data_fim' => 'nullable|date',
            'status' => 'nullable|in:ABERTA,PARCIAL,PAGA,CANCELADA',
            'forma_pagamento' => 'nullable|in:PIX,BOLETO,TED,DINHEIRO,CARTAO',
            'vencidas' => 'nullable|boolean',
        ]);
        $filtro = new FiltroContaPagarDTO(
            busca: $request->string('busca')->toString() ?: null,
            fornecedor_id: $request->integer('fornecedor_id') ?: null,
            status: $request->string('status')->toString() ?: null,
            forma_pagamento: $request->string('forma_pagamento')->toString() ?: null,
            centro_custo: $request->string('centro_custo')->toString() ?: null,
            categoria: $request->string('categoria')->toString() ?: null,
            data_ini: $request->string('data_ini')->toString() ?: null,
            data_fim: $request->string('data_fim')->toString() ?: null,
            vencidas: $request->boolean('vencidas', false),
        );
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 15);

        $paginator = $this->query->listar($filtro, $page, $perPage);
        return ContaPagarResource::collection($paginator);
    }

    public function store(ContaPagarRequest $request): JsonResponse
    {
        $this->authorize('create', ContaPagar::class);
        $resource = $this->cmd->criar($request->validated());
        return response()->json($resource, 201);
    }

    public function show(ContaPagar $conta_pagar): ContaPagarResource
    {
        $conta_pagar->load(['fornecedor','pagamentos.usuario']);
        return new ContaPagarResource($conta_pagar);
    }

    public function update(ContaPagarRequest $request, ContaPagar $conta_pagar): ContaPagarResource
    {
        return $this->cmd->atualizar($conta_pagar, $request->validated());
    }

    public function destroy(ContaPagar $conta_pagar): JsonResponse
    {
        $this->cmd->deletar($conta_pagar);
        return response()->json(['message' => 'Excluída com sucesso']);
    }

    public function pagar(ContaPagarPagamentoRequest $request, ContaPagar $conta_pagar)
    {
        $this->authorize('pagar', $conta_pagar);
        $dados = $request->validated();
        if ($request->hasFile('comprovante')) {
            $dados['comprovante'] = $request->file('comprovante');
        }
        return $this->cmd->registrarPagamento($conta_pagar, $dados);
    }

    public function estornar(ContaPagar $conta_pagar, int $pagamentoId)
    {
        $this->authorize('estornar', $conta_pagar);
        return $this->cmd->estornarPagamento($conta_pagar, $pagamentoId);
    }
}
