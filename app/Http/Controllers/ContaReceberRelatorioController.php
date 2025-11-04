<?php

namespace App\Http\Controllers;

use App\Models\ContaReceber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PDF;

class ContaReceberRelatorioController extends Controller
{
    /**
     * 📊 Retorna relatório consolidado por cliente
     */
    public function devedores(Request $request)
    {
        $query = ContaReceber::query()
            ->select([
                'pedidos.id_cliente',
                DB::raw('clientes.nome AS cliente_nome'),
                DB::raw('SUM(contas_receber.valor_liquido) AS total_liquido'),
                DB::raw('SUM(contas_receber.valor_recebido) AS total_recebido'),
                DB::raw('SUM(contas_receber.saldo_aberto) AS total_aberto'),
                DB::raw('SUM(CASE WHEN contas_receber.data_vencimento < CURRENT_DATE AND contas_receber.status != "PAGO" THEN contas_receber.saldo_aberto ELSE 0 END) AS total_vencido'),
                DB::raw('COUNT(contas_receber.id) AS qtd_titulos'),
                DB::raw('MAX(contas_receber.data_vencimento) AS ultimo_vencimento')
            ])
            ->join('pedidos', 'pedidos.id', '=', 'contas_receber.pedido_id')
            ->join('clientes', 'clientes.id', '=', 'pedidos.id_cliente')
            ->groupBy('pedidos.id_cliente', 'clientes.nome');

        // 🔎 Filtros
        if ($request->filled('data_inicio') && $request->filled('data_fim')) {
            $query->whereBetween('contas_receber.data_vencimento', [$request->data_inicio, $request->data_fim]);
        }

        if ($request->filled('valor_min')) {
            $query->having('total_aberto', '>=', $request->valor_min);
        }

        if ($request->filled('valor_max')) {
            $query->having('total_aberto', '<=', $request->valor_max);
        }

        if ($request->filled('status')) {
            $query->where('contas_receber.status', $request->status);
        }

        $dados = $query->orderByDesc('total_aberto')->get();

        return response()->json([
            'data' => $dados,
            'meta' => [
                'total_clientes' => $dados->count(),
                'soma_aberto'    => $dados->sum('total_aberto'),
                'soma_vencido'   => $dados->sum('total_vencido'),
            ],
        ]);
    }

    /**
     * 📥 Exporta o relatório em Excel
     */
    public function exportarExcel(Request $request)
    {
        $dados = $this->devedores($request)->getData()->data;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Clientes Devedores');

        $sheet->fromArray([
            ['Cliente', 'Títulos', 'Total Líquido', 'Total Recebido', 'Em Aberto', 'Vencido', 'Último Vencimento']
        ]);

        $linha = 2;
        foreach ($dados as $d) {
            $sheet->fromArray([
                $d->cliente_nome,
                $d->qtd_titulos,
                number_format($d->total_liquido, 2, ',', '.'),
                number_format($d->total_recebido, 2, ',', '.'),
                number_format($d->total_aberto, 2, ',', '.'),
                number_format($d->total_vencido, 2, ',', '.'),
                $d->ultimo_vencimento ? date('d/m/Y', strtotime($d->ultimo_vencimento)) : '-',
            ], null, "A{$linha}");
            $linha++;
        }

        $filePath = storage_path('app/public/clientes_devedores.xlsx');
        (new Xlsx($spreadsheet))->save($filePath);

        return response()->download($filePath)->deleteFileAfterSend(true);
    }

    /**
     * 📄 Exporta o relatório em PDF
     */
    public function exportarPdf(Request $request)
    {
        $dados = $this->devedores($request)->getData()->data;

        $pdf = PDF::loadView('pdf.clientes-devedores', [
            'dados' => $dados,
            'geradoEm' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('clientes_devedores.pdf');
    }
}
