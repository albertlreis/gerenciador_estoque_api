<?php

namespace App\Http\Controllers;

use App\Models\ContaReceber;
use App\Http\Resources\ContaReceberResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PDF;

class ContaReceberExportController extends Controller
{
    /**
     * Exporta os resultados filtrados em Excel
     */
    public function exportarExcel(Request $request)
    {
        $contas = $this->filtrarContas($request)->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Contas a Receber');

        $sheet->fromArray([
            ['ID', 'Cliente', 'Pedido', 'Descrição', 'Emissão', 'Vencimento', 'Valor Líquido', 'Recebido', 'Saldo', 'Status']
        ]);

        $linha = 2;
        foreach ($contas as $conta) {
            $sheet->fromArray([
                $conta->id,
                $conta->pedido->cliente->nome ?? '-',
                $conta->pedido->numero ?? '-',
                $conta->descricao,
                optional($conta->data_emissao)->format('d/m/Y'),
                optional($conta->data_vencimento)->format('d/m/Y'),
                number_format($conta->valor_liquido, 2, ',', '.'),
                number_format($conta->valor_recebido, 2, ',', '.'),
                number_format($conta->saldo_aberto, 2, ',', '.'),
                $conta->status
            ], null, "A{$linha}");
            $linha++;
        }

        $filePath = storage_path('app/public/contas_receber.xlsx');
        (new Xlsx($spreadsheet))->save($filePath);

        return response()->download($filePath)->deleteFileAfterSend(true);
    }

    /**
     * Exporta em PDF
     */
    public function exportarPdf(Request $request)
    {
        $contas = $this->filtrarContas($request)->get();

        $pdf = PDF::loadView('pdf.contas-receber', [
            'contas' => $contas,
            'dataGeracao' => now()->format('d/m/Y H:i')
        ])->setPaper('a4', 'landscape');

        return $pdf->download('contas_receber.pdf');
    }

    /**
     * KPIs principais do módulo
     */
    public function kpis(Request $request)
    {
        $query = $this->filtrarContas($request);

        $total = (clone $query)->sum('valor_liquido');
        $recebido = (clone $query)->sum('valor_recebido');
        $aberto = (clone $query)->sum('saldo_aberto');

        $vencidas = (clone $query)
            ->where('status', '!=', 'PAGA')
            ->where('data_vencimento', '<', now())
            ->sum('saldo_aberto');

        $porcentagemRecebido = $total > 0 ? round(($recebido / $total) * 100, 2) : 0;

        return response()->json([
            'total_liquido' => $total,
            'total_recebido' => $recebido,
            'total_aberto' => $aberto,
            'total_vencido' => $vencidas,
            'percentual_recebido' => $porcentagemRecebido,
        ]);
    }

    /**
     * Aplica os filtros padrão da listagem (reutilizável)
     */
    private function filtrarContas(Request $request)
    {
        $query = ContaReceber::with(['pedido.cliente']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('cliente')) {
            $query->whereHas('pedido.cliente', fn($q) =>
            $q->where('nome', 'like', "%{$request->cliente}%"));
        }

        if ($request->filled('data_inicio') && $request->filled('data_fim')) {
            $query->whereBetween('data_vencimento', [$request->data_inicio, $request->data_fim]);
        }

        if ($request->filled('forma_recebimento')) {
            $query->where('forma_recebimento', $request->forma_recebimento);
        }

        return $query;
    }
}
