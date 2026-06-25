<?php

namespace App\Http\Controllers;

use App\Exports\FinanceiroRelatorioExport;
use App\Services\FinanceiroRelatorioService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FinanceiroRelatorioController extends Controller
{
    public function __construct(private readonly FinanceiroRelatorioService $service) {}

    public function show(string $tipo, Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->gerar($tipo, $this->validated($request)),
        ]);
    }

    public function exportExcel(string $tipo, Request $request): BinaryFileResponse
    {
        $dados = $this->service->gerar($tipo, $this->validated($request));

        return Excel::download(new FinanceiroRelatorioExport($dados), $this->filename($tipo, $dados, 'xlsx'));
    }

    public function exportPdf(string $tipo, Request $request): Response
    {
        $dados = $this->service->gerar($tipo, $this->validated($request));

        $pdf = Pdf::loadView('pdf.financeiro-relatorio', [
            'dados' => $dados,
        ])->setPaper('a4', 'landscape');

        return $pdf->download($this->filename($tipo, $dados, 'pdf'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'conta_ids' => ['nullable', 'array'],
            'conta_ids.*' => ['integer', 'exists:contas_financeiras,id'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias_financeiras,id'],
            'centro_custo_id' => ['nullable', 'integer', 'exists:centros_custo,id'],
            'pessoa_id' => ['nullable', 'integer'],
            'tipo_pessoa' => ['nullable', 'in:pagar,receber,ambos'],
            'status' => ['nullable', 'string', 'max:30'],
            'formato' => ['nullable', 'in:padrao'],
        ]);
    }

    private function filename(string $tipo, array $dados, string $ext): string
    {
        $inicio = $dados['periodo']['inicio'] ?? now()->toDateString();
        $fim = $dados['periodo']['fim'] ?? now()->toDateString();

        return "financeiro-{$tipo}-{$inicio}-{$fim}.{$ext}";
    }
}
