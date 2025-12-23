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
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Maatwebsite\Excel\Facades\Excel;

class ContaPagarController extends Controller
{
    public function __construct(
        private readonly ContaPagarQueryService $query,
        private readonly ContaPagarCommandService $cmd,
    ) {}

    /**
     * Lista as contas a pagar com filtros e paginação
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        if ($request->has('vencidas')) {
            $request->merge([
                'vencidas' => filter_var($request->input('vencidas'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        $request->validate([
            'page'         => 'nullable|integer|min:1',
            'per_page'     => 'nullable|integer|min:1|max:200',

            'busca'        => 'nullable|string|max:255',
            'fornecedor_id'=> 'nullable|integer|exists:fornecedores,id',

            'centro_custo' => 'nullable|string|max:60',
            'categoria'    => 'nullable|string|max:60',

            'data_ini'     => 'nullable|date',
            'data_fim'     => 'nullable|date',

            'status'       => 'nullable|in:ABERTA,PARCIAL,PAGA,CANCELADA',
            'vencidas'     => 'nullable|boolean',
        ]);

        $filtro = new FiltroContaPagarDTO(
            busca: $request->string('busca')->toString() ?: null,
            fornecedor_id: $request->integer('fornecedor_id') ?: null,
            status: $request->string('status')->toString() ?: null,
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
        return response()->json(['data' => $resource], 201);
    }

    /**
     * Exibe uma conta a pagar com seus relacionamentos
     */
    public function show(ContaPagar $conta_pagar): JsonResponse
    {
        $conta_pagar->load(['fornecedor', 'pagamentos.usuario']);

        return response()->json([
            'data' => new ContaPagarResource($conta_pagar)
        ], 200);
    }

    /**
     * Atualiza uma conta a pagar existente
     */
    public function update(ContaPagarRequest $request, ContaPagar $conta_pagar): JsonResponse
    {
        $resource = $this->cmd->atualizar($conta_pagar, $request->validated());
        return response()->json(['data' => $resource], 200);
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
     * Registra um pagamento da conta (Fase 1: sempre retorna a conta atualizada)
     */
    public function pagar(ContaPagarPagamentoRequest $request, ContaPagar $conta_pagar): JsonResponse
    {
        $dados = $request->validated();

        if ($request->hasFile('comprovante')) {
            $dados['comprovante'] = $request->file('comprovante');
        }

        // registra pagamento
        $this->cmd->registrarPagamento($conta_pagar, $dados);

        // retorna conta atualizada (status real)
        $conta_pagar->refresh()->load(['fornecedor', 'pagamentos.usuario']);

        return response()->json([
            'data' => new ContaPagarResource($conta_pagar)
        ], 200);
    }

    /**
     * Estorna (remove) um pagamento específico da conta (Fase 1: rota /pagamentos/{pagamento})
     */
    public function estornar(ContaPagar $conta_pagar, int $pagamento): JsonResponse
    {
        // service já retorna resource, mas aqui padronizamos com a conta atualizada
        $this->cmd->estornarPagamento($conta_pagar, $pagamento);

        $conta_pagar->refresh()->load(['fornecedor', 'pagamentos.usuario']);

        return response()->json([
            'data' => new ContaPagarResource($conta_pagar)
        ], 200);
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
     * KPIs do módulo de contas a pagar (aplica filtros principais)
     */
    public function kpis(Request $request): JsonResponse
    {
        if ($request->has('vencidas')) {
            $request->merge([
                'vencidas' => filter_var($request->input('vencidas'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        $request->validate([
            'busca'         => 'nullable|string|max:255',
            'fornecedor_id' => 'nullable|integer|exists:fornecedores,id',
            'status'        => 'nullable|in:ABERTA,PARCIAL,PAGA,CANCELADA',
            'centro_custo'  => 'nullable|string|max:60',
            'categoria'     => 'nullable|string|max:60',
            'data_ini'      => 'nullable|date',
            'data_fim'      => 'nullable|date',
            'vencidas'      => 'nullable|boolean',
        ]);

        $f = new FiltroContaPagarDTO(
            busca: $request->string('busca')->toString() ?: null,
            fornecedor_id: $request->integer('fornecedor_id') ?: null,
            status: $request->string('status')->toString() ?: null,
            centro_custo: $request->string('centro_custo')->toString() ?: null,
            categoria: $request->string('categoria')->toString() ?: null,
            data_ini: $request->string('data_ini')->toString() ?: null,
            data_fim: $request->string('data_fim')->toString() ?: null,
            vencidas: $request->boolean('vencidas', false),
        );

        $query = ContaPagar::query()->with('pagamentos');

        if ($f->busca) {
            $busca = "%{$f->busca}%";
            $query->where(fn($w) => $w
                ->where('descricao','like',$busca)
                ->orWhere('numero_documento','like',$busca)
            );
        }

        if ($f->fornecedor_id) $query->where('fornecedor_id', $f->fornecedor_id);
        if ($f->status)        $query->where('status', $f->status);
        if ($f->centro_custo)  $query->where('centro_custo', $f->centro_custo);
        if ($f->categoria)     $query->where('categoria', $f->categoria);
        if ($f->data_ini)      $query->whereDate('data_vencimento', '>=', $f->data_ini);
        if ($f->data_fim)      $query->whereDate('data_vencimento', '<=', $f->data_fim);

        if ($f->vencidas) {
            $query->whereDate('data_vencimento', '<', now()->toDateString())
                ->where('status', '!=', 'PAGA');
        }

        $linhas = $query->get();

        $totalLiquido = $linhas->sum(fn($c) => $c->valor_bruto - $c->desconto + $c->juros + $c->multa);
        $valorPagoPeriodo = $linhas->sum(fn($c) => $c->pagamentos->sum('valor'));
        $contasPagas = $linhas->filter(fn($c) => ($c->status?->value ?? $c->status) === 'PAGA')->count();
        $contasVencidas = $linhas->filter(fn($c) =>
            (($c->status?->value ?? $c->status) !== 'PAGA') && $c->data_vencimento && $c->data_vencimento->lt(now())
        )->count();

        return response()->json([
            'total_liquido'      => (float) $totalLiquido,
            'valor_pago_periodo' => (float) $valorPagoPeriodo,
            'contas_vencidas'    => $contasVencidas,
            'contas_pagas'       => $contasPagas,
        ], 200);
    }
}
