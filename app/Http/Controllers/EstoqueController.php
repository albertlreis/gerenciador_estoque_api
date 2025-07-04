<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProdutoEstoqueResource;
use App\Http\Resources\ResumoEstoqueResource;
use App\Models\Estoque;
use App\Services\EstoqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstoqueController extends Controller
{
    public function listarEstoqueAtual(Request $request, EstoqueService $service): JsonResponse
    {
        $result = $service->obterEstoqueAgrupadoPorProdutoEDeposito(
            produto: $request->input('produto'),
            deposito: $request->input('deposito'),
            periodo: $request->input('periodo'),
            perPage: $request->input('per_page', 10)
        );

        return ProdutoEstoqueResource::collection($result)->response();
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

    public function porVariacao($id_variacao): JsonResponse
    {
        $estoques = Estoque::with('deposito')
            ->where('id_variacao', $id_variacao)
            ->where('quantidade', '>', 0)
            ->get()
            ->map(function ($e) {
                return [
                    'id' => $e->deposito->id,
                    'nome' => $e->deposito->nome,
                    'quantidade' => $e->quantidade
                ];
            });

        return response()->json($estoques);
    }
}
