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
            'conta_financeira_id' => 'nullable|integer|exists:contas_financeiras,id',
            'data_ini' => 'nullable|date',
            'data_fim' => 'nullable|date',
            'vencidas' => 'nullable|boolean',
            'em_aberto' => 'nullable|boolean',
            'origem' => 'nullable|in:recorrente',
        ]);

        $query = ContasReceberExport::queryFromRequest($request)->with('pagamentos')->reorder();

        $linhas = $query->get();
        $hoje = now()->startOfDay();
        $status = static fn ($conta): string => (string) ($conta->status?->value ?? $conta->status);
        $valorLiquido = static fn ($conta): float => max(
            0,
            (float) $conta->valor_bruto - (float) $conta->desconto + (float) $conta->juros + (float) $conta->multa
        );
        $valorRecebido = static fn ($conta): float => (float) $conta->pagamentos->sum('valor');
        $saldoAberto = static fn ($conta): float => max(0, $valorLiquido($conta) - $valorRecebido($conta));
        $titulosAbertos = $linhas->filter(fn ($conta) => in_array($status($conta), ['ABERTA', 'PARCIAL'], true));
        $titulosRecebidos = $linhas->filter(fn ($conta) => $status($conta) === 'PAGA');
        $titulosVencidos = $titulosAbertos->filter(fn ($conta) => $conta->data_vencimento && $conta->data_vencimento->lt($hoje));
        $titulosVencendoHoje = $titulosAbertos->filter(fn ($conta) => $conta->data_vencimento && $conta->data_vencimento->isSameDay($hoje));
        $titulosAVencer = $titulosAbertos->filter(fn ($conta) => $conta->data_vencimento && $conta->data_vencimento->gt($hoje));

        $aberto = $titulosAbertos->sum($saldoAberto);
        $recebido = $linhas->sum($valorRecebido);
        $total = $aberto + $recebido;
        $vencido = $titulosVencidos->sum($saldoAberto);
        $vencendoHoje = $titulosVencendoHoje->sum($saldoAberto);
        $aVencer = $titulosAVencer->sum($saldoAberto);

        $porcentagemRecebido = $total > 0 ? round(($recebido / $total) * 100, 2) : 0;

        return response()->json([
            'total_liquido' => $total,
            'total_periodo' => $total,
            'total_recebido' => $recebido,
            'total_aberto' => $aberto,
            'total_vencido' => $vencido,
            'total_vencendo_hoje' => $vencendoHoje,
            'total_a_vencer' => $aVencer,
            'qtd_abertas' => $titulosAbertos->count(),
            'qtd_vencidas' => $titulosVencidos->count(),
            'qtd_vencendo_hoje' => $titulosVencendoHoje->count(),
            'qtd_a_vencer' => $titulosAVencer->count(),
            'qtd_recebidas' => $titulosRecebidos->count(),
            'valor_recebido_periodo' => $recebido,
            'contas_vencidas' => $titulosVencidos->count(),
            'contas_recebidas' => $titulosRecebidos->count(),
            'percentual_recebido' => $porcentagemRecebido,
        ]);
    }
}
