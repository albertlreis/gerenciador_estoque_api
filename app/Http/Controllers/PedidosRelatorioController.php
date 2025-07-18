<?php

namespace App\Http\Controllers;

use App\Services\Relatorios\PedidosRelatorioService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "numero": "PED-0001",
     *       "cliente": "João da Silva",
     *       "data": "2024-05-10",
     *       "total": 1245.50,
     *       "status": "finalizado"
     *     }
     *   ]
     * }
     */
    public function pedidosPorPeriodo(Request $request): Response|JsonResponse
    {
        $dados = $this->service->listarPedidosPorPeriodo($request->all());

        if ($request->query('formato') === 'pdf') {
            $pdf = Pdf::loadView('exports.pedidos-por-periodo', ['dados' => $dados]);
            return $pdf->download('relatorio-pedidos.pdf');
        }

        return response()->json(['data' => $dados]);
    }
}
