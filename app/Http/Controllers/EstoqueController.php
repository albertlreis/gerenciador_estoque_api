<?php

namespace App\Http\Controllers;

use App\DTOs\FiltroEstoqueDTO;
use App\Http\Requests\FiltroEstoqueRequest;
use App\Http\Resources\ProdutoEstoqueResource;
use App\Http\Resources\ResumoEstoqueResource;
use App\Models\Estoque;
use App\Services\EstoqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EstoqueController extends Controller
{
    /**
     * Lista o estoque atual agrupado por produto e depósito com filtros e ordenação.
     *
     * @queryParam periodo array Opcional. [YYYY-MM-DD, YYYY-MM-DD] para considerar movimentações no intervalo.
     *
     * @param \App\Http\Requests\FiltroEstoqueRequest $request Instância da requisição HTTP com os parâmetros de filtro
     * @param EstoqueService $service Serviço responsável pela lógica de estoque
     * @return \Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function listarEstoqueAtual(FiltroEstoqueRequest $request, EstoqueService $service): JsonResponse|Response
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $dto = new FiltroEstoqueDTO($request->validated());

        if ($request->get('export') === 'pdf') {
            return $service->exportarPdf($dto);
        }

        $result = $service->listar($dto);

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
        $resumo = $service->gerarResumo(
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
