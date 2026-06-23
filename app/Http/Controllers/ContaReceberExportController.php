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
        foreach (['vencidas', 'em_aberto'] as $booleanFilter) {
            if ($request->has($booleanFilter)) {
                $request->merge([
                    $booleanFilter => filter_var($request->input($booleanFilter), FILTER_VALIDATE_BOOLEAN),
                ]);
            }
        }

        $request->validate([
            'busca' => 'nullable|string|max:255',
            'status' => 'nullable|in:ABERTA,PARCIAL,PAGA,CANCELADA',
            'cliente' => 'nullable|string|max:255',
            'cliente_id' => 'nullable|integer|exists:clientes,id',
            'numero_pedido' => 'nullable|string|max:80',
            'forma_recebimento' => 'nullable|string|max:50',
            'centro_custo_id' => 'nullable|integer|exists:centros_custo,id',
            'categoria_id' => 'nullable|integer|exists:categorias_financeiras,id',
            'data_ini' => 'nullable|date',
            'data_fim' => 'nullable|date',
            'vencidas' => 'nullable|boolean',
            'em_aberto' => 'nullable|boolean',
        ]);

        $query = ContasReceberExport::queryFromRequest($request)->reorder();

        $linhas = $query->get();
        $vencidas = $linhas->filter(fn ($conta) =>
            in_array(($conta->status?->value ?? $conta->status), ['ABERTA', 'PARCIAL'], true)
            && $conta->data_vencimento
            && $conta->data_vencimento->lt(now()->startOfDay())
        );

        $total = $linhas->sum(fn ($conta) => (float) $conta->valor_liquido);
        $recebido = $linhas->sum(fn ($conta) => (float) $conta->valor_recebido);
        $aberto = $linhas->sum(fn ($conta) => (float) $conta->saldo_aberto);
        $vencido = $vencidas->sum(fn ($conta) => (float) $conta->saldo_aberto);
        $qtdRecebidas = $linhas->filter(fn ($conta) => ($conta->status?->value ?? $conta->status) === 'PAGA')->count();
        $qtdAbertas = $linhas->filter(fn ($conta) => in_array(($conta->status?->value ?? $conta->status), ['ABERTA', 'PARCIAL'], true))->count();

        $porcentagemRecebido = $total > 0 ? round(($recebido / $total) * 100, 2) : 0;

        return response()->json([
            'total_liquido' => $total,
            'total_recebido' => $recebido,
            'total_aberto' => $aberto,
            'total_vencido' => $vencido,
            'qtd_abertas' => $qtdAbertas,
            'qtd_vencidas' => $vencidas->count(),
            'qtd_recebidas' => $qtdRecebidas,
            'percentual_recebido' => $porcentagemRecebido,
        ]);
    }
}
