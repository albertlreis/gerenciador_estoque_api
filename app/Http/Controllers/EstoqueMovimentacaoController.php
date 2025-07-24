<?php

namespace App\Http\Controllers;

use App\DTOs\FiltroMovimentacaoEstoqueDTO;
use App\Http\Requests\StoreMovimentacaoRequest;
use App\Http\Requests\UpdateMovimentacaoRequest;
use App\Http\Resources\MovimentacaoResource;
use App\Models\Produto;
use App\Models\EstoqueMovimentacao;
use App\Services\EstoqueMovimentacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlador responsável por gerenciar movimentações de estoque.
 */
class EstoqueMovimentacaoController extends Controller
{
    protected EstoqueMovimentacaoService $service;

    /**
     * Construtor com injeção do service de movimentações.
     *
     * @param EstoqueMovimentacaoService $service
     */
    public function __construct(EstoqueMovimentacaoService $service)
    {
        $this->service = $service;
    }

    /**
     * Lista movimentações de estoque com filtros opcionais e ordenação.
     *
     * @param Request $request Instância da requisição HTTP com os filtros de busca
     * @param EstoqueMovimentacaoService $service Serviço responsável pela lógica de movimentações
     * @return JsonResponse Lista paginada de movimentações de estoque
     */
    public function index(Request $request, EstoqueMovimentacaoService $service): JsonResponse
    {
        $dto = new FiltroMovimentacaoEstoqueDTO($request->all());

        $movimentacoes = $service->buscarComFiltros($dto);

        return MovimentacaoResource::collection($movimentacoes)->response();
    }

    /**
     * Armazena uma nova movimentação de estoque.
     *
     * @param StoreMovimentacaoRequest $request
     * @param Produto $produto
     * @return JsonResponse
     */
    public function store(StoreMovimentacaoRequest $request, Produto $produto): JsonResponse
    {
        $dados = $request->validated();
        $dados['id_produto'] = $produto->id;

        $movimentacao = EstoqueMovimentacao::create($dados);
        return response()->json(new MovimentacaoResource($movimentacao), 201);
    }

    /**
     * Exibe uma movimentação específica de um produto.
     *
     * @param Produto $produto
     * @param EstoqueMovimentacao $movimentacao
     * @return JsonResponse
     */
    public function show(Produto $produto, EstoqueMovimentacao $movimentacao): JsonResponse
    {
        if ($movimentacao->id_produto !== $produto->id) {
            return response()->json(['error' => 'Movimentação não pertence a este produto'], 404);
        }

        return response()->json(new MovimentacaoResource($movimentacao));
    }

    /**
     * Atualiza uma movimentação de estoque existente.
     *
     * @param UpdateMovimentacaoRequest $request
     * @param Produto $produto
     * @param EstoqueMovimentacao $movimentacao
     * @return JsonResponse
     */
    public function update(UpdateMovimentacaoRequest $request, Produto $produto, EstoqueMovimentacao $movimentacao): JsonResponse
    {
        if ($movimentacao->id_produto !== $produto->id) {
            return response()->json(['error' => 'Movimentação não pertence a este produto'], 404);
        }

        $movimentacao->update($request->validated());
        return response()->json(new MovimentacaoResource($movimentacao));
    }

    /**
     * Remove uma movimentação de estoque.
     *
     * @param Produto $produto
     * @param EstoqueMovimentacao $movimentacao
     * @return JsonResponse
     */
    public function destroy(Produto $produto, EstoqueMovimentacao $movimentacao): JsonResponse
    {
        if ($movimentacao->id_produto !== $produto->id) {
            return response()->json(['error' => 'Movimentação não pertence a este produto'], 404);
        }

        $movimentacao->delete();
        return response()->json(null, 204);
    }
}
