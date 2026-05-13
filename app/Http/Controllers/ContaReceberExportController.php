<?php

namespace App\Http\Controllers;

use App\Exports\ContasReceberExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContaReceberExportController extends Controller
{
    /**
     * Exporta os resultados filtrados em Excel.
     */
    public function exportarExcel(Request $request): BinaryFileResponse
    {
        return Excel::download(new ContasReceberExport($request->all()), 'contas_receber.xlsx');
    }

    /**
     * Exporta os resultados filtrados em PDF.
     */
    public function exportarPdf(Request $request): Response
    {
        $contas = ContasReceberExport::queryFromRequest($request)->get();

        $pdf = Pdf::loadView('pdf.contas-receber', [
            'contas' => $contas,
            'dataGeracao' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('contas_receber.pdf');
    }

    /**
     * KPIs principais do modulo.
     */
    public function kpis(Request $request)
    {
        $query = ContasReceberExport::queryFromRequest($request)->reorder();

        $total = (clone $query)->get()->sum(fn ($conta) => (float) $conta->valor_liquido);
        $recebido = (clone $query)->get()->sum(fn ($conta) => (float) $conta->valor_recebido);
        $aberto = (clone $query)->get()->sum(fn ($conta) => (float) $conta->saldo_aberto);

        $vencidas = (clone $query)
            ->where('status', '!=', 'PAGA')
            ->whereDate('data_vencimento', '<', now()->toDateString())
            ->get()
            ->sum(fn ($conta) => (float) $conta->saldo_aberto);

        $porcentagemRecebido = $total > 0 ? round(($recebido / $total) * 100, 2) : 0;

        return response()->json([
            'total_liquido' => $total,
            'total_recebido' => $recebido,
            'total_aberto' => $aberto,
            'total_vencido' => $vencidas,
            'percentual_recebido' => $porcentagemRecebido,
        ]);
    }
}
