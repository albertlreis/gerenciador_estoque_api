<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProdutoEstoqueResource;
use App\Http\Resources\ResumoEstoqueResource;
use App\Services\EstoqueService;
use Illuminate\Http\JsonResponse;

class EstoqueController extends Controller
{
    public function listarEstoqueAtual(EstoqueService $service): JsonResponse
    {
        $dados = $service->obterEstoqueAgrupadoPorProdutoEDeposito();
        return response()->json(
            ProdutoEstoqueResource::collection($service->obterEstoqueAgrupadoPorProdutoEDeposito())
        );
    }

    public function resumoEstoque(EstoqueService $service): JsonResponse
    {
        return response()->json(new ResumoEstoqueResource($service->gerarResumoEstoque()));
    }

}
