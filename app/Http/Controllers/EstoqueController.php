<?php

namespace App\Http\Controllers;

use App\DTOs\FiltroEstoqueDTO;
use App\Http\Resources\ProdutoEstoqueResource;
use App\Http\Resources\ResumoEstoqueResource;
use App\Models\Estoque;
use App\Services\EstoqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstoqueController extends Controller
{
    /**
     * Lista o estoque atual agrupado por produto e depósito com filtros e ordenação.
     *
     * @param Request $request Instância da requisição HTTP com os parâmetros de filtro
     * @param EstoqueService $service Serviço responsável pela lógica de estoque
     * @return JsonResponse Lista paginada de produtos com estoque
     */
    public function listarEstoqueAtual(Request $request, EstoqueService $service): JsonResponse
    {
        $dto = new FiltroEstoqueDTO($request->all());

        $result = $service->obterEstoqueAgrupadoPorProdutoEDeposito($dto);

        return ProdutoEstoqueResource::collection($result)->response();
    }

    /**
     * Retorna um resumo com total de produtos, peças e depósitos.
     *
     * @param Request $request
     * @param EstoqueService $service
     * @return JsonResponse
     */
    public function resumoEstoque(Request $request, EstoqueService $service): JsonResponse
    {
        $resumo = $service->gerarResumoEstoque(
            produto: $request->input('produto'),
            deposito: $request->input('deposito'),
            periodo: $request->input('periodo')
        );

        return response()->json(new ResumoEstoqueResource($resumo));
    }

    /**
     * Lista os depósitos com estoque positivo de uma variação específica.
     *
     * @param int $id_variacao
     * @return JsonResponse
     */
    public function porVariacao(int $id_variacao): JsonResponse
    {
        $estoques = Estoque::with('deposito')
            ->where('id_variacao', $id_variacao)
            ->where('quantidade', '>', 0)
            ->get()
            ->filter(fn($e) => $e->deposito)
            ->map(fn($e) => [
                'id' => $e->deposito->id,
                'nome' => $e->deposito->nome,
                'quantidade' => $e->quantidade
            ])
            ->values();

        return response()->json($estoques);
    }
}
