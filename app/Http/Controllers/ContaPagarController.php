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
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        foreach (['vencidas', 'em_aberto'] as $booleanFilter) {
            if ($request->has($booleanFilter)) {
                $request->merge([
                    $booleanFilter => filter_var($request->input($booleanFilter), FILTER_VALIDATE_BOOLEAN),
                ]);
            }
        }

        $request->validate([
            'page'         => 'nullable|integer|min:1',
            'per_page'     => 'nullable|integer|min:1|max:200',

            'busca'        => 'nullable|string|max:255',
            'fornecedor_id'=> 'nullable|integer|exists:fornecedores,id',
            'forma_pagamento' => 'nullable|string|max:50',

            'centro_custo_id' => 'nullable|integer|exists:centros_custo,id',
            'categoria_id'    => 'nullable|integer|exists:categorias_financeiras,id',
            'conta_financeira_id' => 'nullable|integer|exists:contas_financeiras,id',

            'data_ini'     => 'nullable|date',
            'data_fim'     => 'nullable|date',

            'status'       => 'nullable|in:ABERTA,PARCIAL,PAGA,CANCELADA',
            'vencidas'     => 'nullable|boolean',
            'em_aberto'    => 'nullable|boolean',
            'origem'       => 'nullable|in:recorrente',
        ]);

        $filtro = new FiltroContaPagarDTO(
            busca: $request->string('busca')->toString() ?: null,
            fornecedor_id: $request->integer('fornecedor_id') ?: null,
            status: $request->string('status')->toString() ?: null,
            forma_pagamento: $request->string('forma_pagamento')->toString() ?: null,
            centro_custo_id: $request->integer('centro_custo_id') ?: null,
            categoria_id: $request->integer('categoria_id') ?: null,
            data_ini: $request->string('data_ini')->toString() ?: null,
            data_fim: $request->string('data_fim')->toString() ?: null,
            vencidas: $request->boolean('vencidas', false),
            em_aberto: $request->boolean('em_aberto', false),
            origem: $request->string('origem')->toString() ?: null,
            conta_financeira_id: $request->integer('conta_financeira_id') ?: null,
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
        $conta_pagar->load([
            'fornecedor:id,nome',
            'categoria:id,nome,tipo',
            'centroCusto:id,nome',
            'parcelamento',
            'recorrencia',
            'pagamentos' => function ($q) {
                $q->orderByDesc('data_pagamento')
                    ->with([
                        'usuario:id,nome',
                        'contaFinanceira:id,nome',
                    ]);
            },
        ]);

        return response()->json([
            'data' => new ContaPagarResource($conta_pagar),
        ]);
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
        $conta_pagar->refresh()->load([
            'fornecedor', 'categoria', 'centroCusto', 'recorrencia',
            'pagamentos.usuario', 'pagamentos.contaFinanceira'
        ]);

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

        $conta_pagar->refresh()->load([
            'fornecedor', 'categoria', 'centroCusto', 'recorrencia',
            'pagamentos.usuario', 'pagamentos.contaFinanceira'
        ]);

        return response()->json([
            'data' => new ContaPagarResource($conta_pagar)
        ]);
    }

    /**
     * Exporta os dados para Excel
     */
    public function exportExcel(Request $request): BinaryFileResponse
    {
        $export = new ContasPagarExport($request->all());
        return Excel::download($export, 'contas_pagar.xlsx');
    }

    /**
     * Exporta os dados para PDF
     */
    public function exportPdf(Request $request): Response
    {
        $dados = (new ContasPagarExport($request->all()))->collection();

        $pdf = Pdf::loadView('pdf.contas_pagar', [
            'linhas' => $dados,
            'gerado_em' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('contas_pagar.pdf');
    }

    /**
     * KPIs do módulo de contas a pagar (aplica filtros principais)
     */
    public function kpis(Request $request): JsonResponse
    {
        foreach (['vencidas', 'em_aberto'] as $booleanFilter) {
            if ($request->has($booleanFilter)) {
                $request->merge([
                    $booleanFilter => filter_var($request->input($booleanFilter), FILTER_VALIDATE_BOOLEAN),
                ]);
            }
        }

        $request->validate([
            'busca'         => 'nullable|string|max:255',
            'fornecedor_id' => 'nullable|integer|exists:fornecedores,id',
            'forma_pagamento' => 'nullable|string|max:50',
            'status'        => 'nullable|in:ABERTA,PARCIAL,PAGA,CANCELADA',
            'centro_custo_id'  => 'nullable|integer|exists:centros_custo,id',
            'categoria_id'     => 'nullable|integer|exists:categorias_financeiras,id',
            'conta_financeira_id' => 'nullable|integer|exists:contas_financeiras,id',
            'data_ini'      => 'nullable|date',
            'data_fim'      => 'nullable|date',
            'vencidas'      => 'nullable|boolean',
            'em_aberto'     => 'nullable|boolean',
            'origem'        => 'nullable|in:recorrente',
        ]);

        $f = new FiltroContaPagarDTO(
            busca: $request->string('busca')->toString() ?: null,
            fornecedor_id: $request->integer('fornecedor_id') ?: null,
            status: $request->string('status')->toString() ?: null,
            forma_pagamento: $request->string('forma_pagamento')->toString() ?: null,
            centro_custo_id: $request->integer('centro_custo_id') ?: null,
            categoria_id: $request->integer('categoria_id') ?: null,
            data_ini: $request->string('data_ini')->toString() ?: null,
            data_fim: $request->string('data_fim')->toString() ?: null,
            vencidas: $request->boolean('vencidas', false),
            em_aberto: $request->boolean('em_aberto', false),
            origem: $request->string('origem')->toString() ?: null,
            conta_financeira_id: $request->integer('conta_financeira_id') ?: null,
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
        if ($f->forma_pagamento) $query->where('forma_pagamento', $f->forma_pagamento);
        if ($f->centro_custo_id)  $query->where('centro_custo_id', $f->centro_custo_id);
        if ($f->categoria_id)     $query->where('categoria_id', $f->categoria_id);
        if ($f->conta_financeira_id) {
            $query->whereHas('pagamentos', fn ($q) => $q->where('conta_financeira_id', $f->conta_financeira_id));
        }
        if ($f->origem === 'recorrente') $query->whereNotNull('despesa_recorrente_id');
        if ($f->data_ini)      $query->whereDate('data_vencimento', '>=', $f->data_ini);
        if ($f->data_fim)      $query->whereDate('data_vencimento', '<=', $f->data_fim);

        if ($f->em_aberto) {
            $query->whereNotIn('status', ['PAGA', 'CANCELADA']);
        }

        if ($f->vencidas) {
            $query->whereDate('data_vencimento', '<', now()->toDateString())
                ->whereNotIn('status', ['PAGA', 'CANCELADA']);
        }

        $linhas = $query->get();
        $hoje = now()->startOfDay();
        $status = static fn($c): string => (string) ($c->status?->value ?? $c->status);
        $valorLiquido = static fn($c): float => max(
            0,
            (float) $c->valor_bruto - (float) $c->desconto + (float) $c->juros + (float) $c->multa
        );
        $valorPago = static fn($c): float => (float) $c->pagamentos->sum('valor');
        $saldoAberto = static fn($c): float => max(0, $valorLiquido($c) - $valorPago($c));
        $titulosAbertos = $linhas->filter(fn($c) => in_array($status($c), ['ABERTA', 'PARCIAL'], true));
        $titulosPagos = $linhas->filter(fn($c) => $status($c) === 'PAGA');
        $titulosVencidos = $titulosAbertos->filter(fn($c) => $c->data_vencimento && $c->data_vencimento->lt($hoje));
        $titulosVencendoHoje = $titulosAbertos->filter(fn($c) => $c->data_vencimento && $c->data_vencimento->isSameDay($hoje));
        $titulosAVencer = $titulosAbertos->filter(fn($c) => $c->data_vencimento && $c->data_vencimento->gt($hoje));

        $totalAberto = $titulosAbertos->sum($saldoAberto);
        $totalPago = $linhas->sum($valorPago);
        $totalLiquido = $totalAberto + $totalPago;
        $totalVencido = $titulosVencidos->sum($saldoAberto);
        $totalVencendoHoje = $titulosVencendoHoje->sum($saldoAberto);
        $totalAVencer = $titulosAVencer->sum($saldoAberto);

        return response()->json([
            'total_liquido'      => (float) $totalLiquido,
            'total_periodo'      => (float) $totalLiquido,
            'total_aberto'       => (float) $totalAberto,
            'total_vencido'      => (float) $totalVencido,
            'total_pago'         => (float) $totalPago,
            'total_vencendo_hoje'=> (float) $totalVencendoHoje,
            'total_a_vencer'     => (float) $totalAVencer,
            'qtd_abertas'        => $titulosAbertos->count(),
            'qtd_vencidas'       => $titulosVencidos->count(),
            'qtd_vencendo_hoje'  => $titulosVencendoHoje->count(),
            'qtd_a_vencer'       => $titulosAVencer->count(),
            'qtd_pagas'          => $titulosPagos->count(),
            'valor_pago_periodo' => (float) $totalPago,
            'contas_vencidas'    => $titulosVencidos->count(),
            'contas_pagas'       => $titulosPagos->count(),
        ], 200);
    }
}
