<?php

namespace App\Http\Controllers;

use App\DTOs\FiltroLancamentoFinanceiroDTO;
use App\Exports\LancamentosFinanceirosExport;
use App\Http\Requests\Financeiro\LancamentoFinanceiroIndexRequest;
use App\Http\Requests\Financeiro\LancamentoFinanceiroStoreRequest;
use App\Http\Requests\Financeiro\LancamentoFinanceiroUpdateRequest;
use App\Http\Resources\LancamentoFinanceiroResource;
use App\Models\LancamentoFinanceiro;
use App\Services\LancamentoFinanceiroService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
                    'page' => $pag->currentPage(),
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
            'message' => 'Movimento criado com sucesso.',
            'data'    => new LancamentoFinanceiroResource($model),
        ], 201);
    }

    public function update(LancamentoFinanceiroUpdateRequest $request, LancamentoFinanceiro $lancamento): JsonResponse
    {
        $model = $this->service->atualizar($lancamento, $request->validated());

        return response()->json([
            'message' => 'Movimento atualizado com sucesso.',
            'data'    => new LancamentoFinanceiroResource($model),
        ]);
    }

    public function destroy(LancamentoFinanceiro $lancamento): JsonResponse
    {
        $this->service->remover($lancamento);

        return response()->json(['message' => 'Movimento removido com sucesso.']);
    }

    public function totais(LancamentoFinanceiroIndexRequest $request): JsonResponse
    {
        $dto = new FiltroLancamentoFinanceiroDTO($request->validated());

        return response()->json([
            'data' => $this->service->totais($dto),
        ]);
    }

    public function exportExcel(LancamentoFinanceiroIndexRequest $request): BinaryFileResponse
    {
        $dto = new FiltroLancamentoFinanceiroDTO($request->validated());

        return Excel::download(
            new LancamentosFinanceirosExport($this->service->listarParaExportacao($dto)),
            'extrato_movimentacoes.xlsx'
        );
    }

    public function exportPdf(LancamentoFinanceiroIndexRequest $request): Response
    {
        $dto = new FiltroLancamentoFinanceiroDTO($request->validated());
        $lancamentos = $this->service->listarParaExportacao($dto);

        $pdf = Pdf::loadView('pdf.financeiro-lancamentos', [
            'lancamentos' => $lancamentos,
            'totais' => $this->service->totais($dto),
            'gerado_em' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('extrato_movimentacoes.pdf');
    }

    public function recibo(LancamentoFinanceiro $lancamento): Response
    {
        $pdf = Pdf::loadView('pdf.financeiro-recibo', $this->service->dadosRecibo($lancamento))
            ->setPaper('a4', 'portrait');

        return $pdf->download("recibo-movimento-{$lancamento->id}.pdf");
    }
}
