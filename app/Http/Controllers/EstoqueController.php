<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProdutoEstoqueResource;
use App\Http\Resources\ResumoEstoqueResource;
use App\Services\EstoqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstoqueController extends Controller
{
    public function listarEstoqueAtual(Request $request, EstoqueService $service): JsonResponse
    {
        return response()->json(
            ProdutoEstoqueResource::collection(
                $service->obterEstoqueAgrupadoPorProdutoEDeposito(
                    produto: $request->input('produto'),
                    deposito: $request->input('deposito'),
                    periodo: $request->input('periodo')
                )
            )
        );
    }

    public function resumoEstoque(Request $request, EstoqueService $service): JsonResponse
    {
        return response()->json(
            new ResumoEstoqueResource(
                $service->gerarResumoEstoque(
                    produto: $request->input('produto'),
                    deposito: $request->input('deposito'),
                    periodo: $request->input('periodo')
                )
            )
        );
    }
}
