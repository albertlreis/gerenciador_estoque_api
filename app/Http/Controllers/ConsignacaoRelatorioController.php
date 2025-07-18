<?php

namespace App\Http\Controllers;

use App\Services\Relatorios\ConsignacaoRelatorioService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
     * Relatório de Consignações Ativas
     *
     * Retorna consignações abertas com produtos e clientes vinculados.
     *
     * @queryParam cliente_id int Opcional. Filtrar por cliente.
     * @queryParam parceiro_id int Opcional. Filtrar por parceiro.
     * @queryParam vencimento_ate date Opcional. Consignações com vencimento até esta data.
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "cliente": "Maria Oliveira",
     *       "data_envio": "2024-06-01",
     *       "status": "aberta",
     *       "total": 980.00
     *     }
     *   ]
     * }
     */
    public function consignacoesAtivas(Request $request): Response|JsonResponse
    {
        $dados = $this->service->listarConsignacoesAtivas($request->all());

        if ($request->query('formato') === 'pdf') {
            $pdf = Pdf::loadView('exports.consignacoes-ativas', ['dados' => $dados]);
            return $pdf->download('relatorio-consignacoes.pdf');
        }

        return response()->json(['data' => $dados]);
    }
}
