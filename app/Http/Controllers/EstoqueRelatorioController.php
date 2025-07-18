<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Relatorios\EstoqueRelatorioService;
use Illuminate\Http\Response;

/**
 * @group Relatórios: Estoque
 *
 * Endpoints relacionados aos relatórios de estoque
 */
class EstoqueRelatorioController extends Controller
{
    protected EstoqueRelatorioService $service;

    public function __construct(EstoqueRelatorioService $service)
    {
        $this->service = $service;
    }

    /**
     * Relatório de Estoque Atual
     *
     * Retorna o estoque atual por produto e depósito.
     *
     * @queryParam deposito_id int Opcional. Filtra por depósito.
     * @queryParam categoria_id int Opcional. Filtra por categoria de produto.
     * @queryParam critico bool Opcional. Retorna apenas produtos com estoque abaixo do mínimo.
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "produto": "CADEIRA X",
     *       "estoque_total": 12,
     *       "estoque_por_deposito": {
     *         "DEP 1": 5,
     *         "DEP 2": 7
     *       }
     *     }
     *   ]
     * }
     */
    public function estoqueAtual(Request $request): Response|JsonResponse
    {
        $dados = $this->service->obterEstoqueAtual($request->all());

        if ($request->query('formato') === 'pdf') {
            $pdf = Pdf::loadView('exports.estoque-atual', ['dados' => $dados]);
            return $pdf->download('relatorio-estoque.pdf');
        }

        return response()->json(['data' => $dados]);
    }
}
