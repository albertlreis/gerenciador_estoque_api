<?php

namespace App\Http\Controllers;

use App\Exports\Relatorios\PedidosPorPeriodoExport;
use App\Services\Relatorios\PedidosRelatorioService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * @group Relatórios: Pedidos
 *
 * Endpoints relacionados aos relatórios de pedidos
 */
class PedidosRelatorioController extends Controller
{
    protected PedidosRelatorioService $service;

    public function __construct(PedidosRelatorioService $service)
    {
        $this->service = $service;
    }

    /**
     * Relatório de Pedidos por Período
     *
     * Lista os pedidos filtrados por data e critérios opcionais.
     *
     * @queryParam data_inicio date Obrigatório. Data inicial no formato YYYY-MM-DD.
     * @queryParam data_fim date Obrigatório. Data final no formato YYYY-MM-DD.
     * @queryParam cliente_id int Opcional. Filtrar por cliente.
     * @queryParam status string Opcional. Ex: "finalizado", "pendente".
     * @queryParam vendedor_id int Opcional.
     */
    public function pedidosPorPeriodo(Request $request): Response|JsonResponse|BinaryFileResponse
    {
        [$dados, $totalGeral] = $this->service->listarPedidosPorPeriodo($request->all());

        $formato = $request->query('formato');

        if ($formato === 'pdf') {
            $pdf = Pdf::loadView('exports.pedidos-por-periodo', [
                'dados' => $dados,
                'totalGeral' => $totalGeral,
            ]);
            return $pdf->download('relatorio-pedidos.pdf');
        }

        if ($formato === 'excel') {
            return Excel::download(
                new PedidosPorPeriodoExport($dados, $totalGeral),
                'relatorio-pedidos.xlsx'
            );
        }

        return response()->json(['data' => $dados, 'total_geral' => $totalGeral]);
    }
}
