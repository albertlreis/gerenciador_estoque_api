<?php

namespace App\Http\Controllers;

use App\DTOs\FiltroContaPagarDTO;
use App\Exports\ContasPagarExport;
use App\Http\Requests\Financeiro\ContaPagarPagamentoRequest;
use App\Http\Requests\Financeiro\ContaPagarRequest;
use App\Http\Resources\ContaPagarResource;
use App\Models\ContaPagar;
use App\Services\ContaPagarCommandService;
use App\Services\ContaPagarQueryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ContaPagarController extends Controller
{
    public function __construct(
        private readonly ContaPagarQueryService $query,
        private readonly ContaPagarCommandService $cmd,
    ) {
        // $this->middleware('auth:sanctum');
        // $this->authorizeResource(ContaPagar::class, 'conta_pagar');
    }

    /**
     * Lista as contas a pagar com filtros e paginação
     */
    public function index(Request $request)
    {
        if ($request->has('vencidas')) {
            $request->merge([
                'vencidas' => filter_var($request->input('vencidas'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        $request->validate([
            'page'            => 'nullable|integer|min:1',
            'per_page'        => 'nullable|integer|min:1|max:200',
            'data_ini'        => 'nullable|date',
            'data_fim'        => 'nullable|date',
            'status'          => 'nullable|in:ABERTA,PARCIAL,PAGA,CANCELADA',
            'forma_pagamento' => 'nullable|in:PIX,BOLETO,TED,DINHEIRO,CARTAO',
            'vencidas'        => 'nullable|boolean',
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

    /**
     * Cria uma nova conta a pagar
     */
    public function store(ContaPagarRequest $request): JsonResponse
    {
        $resource = $this->cmd->criar($request->validated());
        return response()->json($resource, 201);
    }

    /**
     * Exibe uma conta a pagar com seus relacionamentos
     */
    public function show(int $id): JsonResponse
    {
        $conta = ContaPagar::with(['fornecedor', 'pagamentos.usuario'])->findOrFail($id);

        return response()->json([
            'data' => new ContaPagarResource($conta)
        ]);
    }

    /**
     * Atualiza uma conta a pagar existente
     */
    public function update(ContaPagarRequest $request, ContaPagar $conta_pagar): ContaPagarResource
    {
        return $this->cmd->atualizar($conta_pagar, $request->validated());
    }

    /**
     * Exclui uma conta a pagar
     */
    public function destroy(ContaPagar $conta_pagar): JsonResponse
    {
        $this->cmd->deletar($conta_pagar);
        return response()->json(['message' => 'Excluída com sucesso'], 200);
    }

    /**
     * Registra um pagamento de conta
     */
    public function pagar(ContaPagarPagamentoRequest $request, ContaPagar $conta_pagar)
    {
        $dados = $request->validated();

        if ($request->hasFile('comprovante')) {
            $dados['comprovante'] = $request->file('comprovante');
        }

        $pagamento = $this->cmd->registrarPagamento($conta_pagar, $dados);
        return response()->json($pagamento, 201);
    }

    /**
     * Estorna um pagamento de uma conta
     */
    public function estornar(ContaPagar $conta_pagar, int $pagamentoId)
    {
        $recurso = $this->cmd->estornarPagamento($conta_pagar, $pagamentoId);
        return response()->json($recurso, 200);
    }

    /**
     * Exporta os dados para Excel
     */
    public function exportExcel(Request $request)
    {
        $export = new ContasPagarExport($request->all());
        return Excel::download($export, 'contas_pagar.xlsx');
    }

    /**
     * Exporta os dados para PDF
     */
    public function exportPdf(Request $request)
    {
        $dados = (new ContasPagarExport($request->all()))->collection();

        $pdf = Pdf::loadView('pdf.contas_pagar', [
            'linhas' => $dados,
            'gerado_em' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('contas_pagar.pdf');
    }

    /**
     * Retorna os KPIs (indicadores) do módulo de contas a pagar
     */
    public function kpis(Request $request): JsonResponse
    {
        $f = new FiltroContaPagarDTO(
            busca: $request->string('busca')->toString() ?: null,
            fornecedor_id: $request->integer('fornecedor_id') ?: null,
            status: $request->string('status')->toString() ?: null,
            forma_pagamento: $request->string('forma_pagamento')->toString() ?: null,
            centro_custo: $request->string('centro_custo')->toString() ?: null,
            categoria: $request->string('categoria')->toString() ?: null,
            data_ini: $request->string('data_ini')->toString() ?: null,
            data_fim: $request->string('data_fim')->toString() ?: null,
            vencidas: $request->boolean('vencidas'),
        );

        $query = ContaPagar::with('pagamentos')
            ->when($f->status, fn($q) => $q->where('status', $f->status));

        if ($f->forma_pagamento) $query->where('forma_pagamento', $f->forma_pagamento);
        if ($f->centro_custo)    $query->where('centro_custo', $f->centro_custo);
        if ($f->categoria)       $query->where('categoria', $f->categoria);
        if ($f->data_ini)        $query->whereDate('data_vencimento', '>=', $f->data_ini);
        if ($f->data_fim)        $query->whereDate('data_vencimento', '<=', $f->data_fim);

        $linhas = $query->get();

        $totalLiquido = $linhas->sum(fn($c) => $c->valor_bruto - $c->desconto + $c->juros + $c->multa);
        $valorPagoPeriodo = $linhas->sum(fn($c) => $c->pagamentos->sum('valor'));
        $contasPagas = $linhas->filter(fn($c) => $c->status->value === 'PAGA')->count();
        $contasVencidas = $linhas->filter(fn($c) =>
            $c->status->value !== 'PAGA' && $c->data_vencimento->lt(now())
        )->count();

        return response()->json([
            'total_liquido'      => (float) $totalLiquido,
            'valor_pago_periodo' => (float) $valorPagoPeriodo,
            'contas_vencidas'    => $contasVencidas,
            'contas_pagas'       => $contasPagas,
        ]);
    }
}
