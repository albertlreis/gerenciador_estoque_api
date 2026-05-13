<?php

namespace App\Http\Controllers;

use App\Exports\FinanceiroExtratoExport;
use App\Services\FinanceiroExtratoService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FinanceiroExtratoController extends Controller
{
    public function __construct(private readonly FinanceiroExtratoService $service) {}

    public function exportPdf(Request $request): Response
    {
        $dados = $this->service->montar($this->validated($request));

        $pdf = Pdf::loadView('pdf.financeiro-extrato', [
            ...$dados,
        ])->setPaper('a4', 'landscape');

        return $pdf->download('relatorio_extrato_financeiro.pdf');
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        $dados = $this->service->montar($this->validated($request));

        return Excel::download(new FinanceiroExtratoExport($dados), 'relatorio_extrato_financeiro.xlsx');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'conta_id' => ['required', 'integer', 'exists:contas_financeiras,id'],
        ]);
    }
}
