<?php

namespace App\Http\Controllers;

use App\Exports\Relatorios\ConsignacoesExport;
use App\Services\Relatorios\ConsignacaoRelatorioService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * @group Relatórios: Consignações
 *
 * Endpoints relacionados aos relatórios de consignações
 */
class ConsignacaoRelatorioController extends Controller
{
    protected ConsignacaoRelatorioService $service;

    public function __construct(ConsignacaoRelatorioService $service)
    {
        $this->service = $service;
    }

    /**
     * Relatório de Consignações (ativas ou por filtros)
     *
     * Filtros:
     * - status: um dos valores de STATUS_CONSIGNACAO
     * - envio_inicio, envio_fim (YYYY-MM-DD)
     * - vencimento_inicio, vencimento_fim (YYYY-MM-DD)
     * - consolidado: bool (true => agrupar por cliente; false => detalhado)
     */
    public function consignacoesAtivas(Request $request): Response|JsonResponse|BinaryFileResponse
    {
        [$linhas, $totalGeral, $consolidado] = $this->service->listarConsignacoes($request->all());

        $formato = $request->query('formato');

        if ($formato === 'pdf') {
            $pdf = Pdf::loadView('exports.consignacoes-ativas', [
                'dados'       => $linhas,
                'totalGeral'  => $totalGeral,
                'consolidado' => $consolidado,
            ]);
            return $pdf->download('relatorio-consignacoes.pdf');
        }

        if ($formato === 'excel') {
            return Excel::download(
                new ConsignacoesExport($linhas, $totalGeral, $consolidado),
                'relatorio-consignacoes.xlsx'
            );
        }

        return response()->json([
            'data'         => $linhas,
            'total_geral'  => $totalGeral,
            'consolidado'  => $consolidado,
        ]);
    }

}
