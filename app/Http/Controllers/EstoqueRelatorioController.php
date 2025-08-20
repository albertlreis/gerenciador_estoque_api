<?php

namespace App\Http\Controllers;

use App\Exports\Relatorios\EstoqueAtualExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Relatorios\EstoqueRelatorioService;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
     * @queryParam deposito_ids[] int Opcional. Array de depósitos para filtrar (ex.: deposito_ids[]=1&deposito_ids[]=2).
     * @queryParam deposito_id int Opcional. (legado) Filtra por depósito único.
     * @queryParam categoria_id int Opcional. Filtra por categoria de produto.
     * @queryParam critico bool Opcional. Retorna apenas produtos com estoque abaixo do mínimo (se aplicável).
     * @queryParam somente_outlet bool Opcional. Retorna apenas variações com outlet disponível.
     * @queryParam formato ‘string’ Opcional. 'Pdf' ou 'Excel' (se implementado). Padrão: JSON.
     */
    public function estoqueAtual(Request $request): Response|JsonResponse|BinaryFileResponse
    {
        $dados = $this->service->obterEstoqueAtual($request->all());

        $formato = $request->query('formato');

        if ($formato === 'pdf') {
            $pdf = Pdf::loadView('exports.estoque-atual', ['dados' => $dados]);
            return $pdf->download('relatorio-estoque.pdf');
        }

        if ($formato === 'excel') {
            return Excel::download(
                new EstoqueAtualExport($dados),
                'relatorio-estoque.xlsx'
            );
        }

        return response()->json(['data' => $dados]);
    }
}
