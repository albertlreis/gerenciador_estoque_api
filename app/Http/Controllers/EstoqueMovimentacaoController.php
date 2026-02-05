<?php

namespace App\Http\Controllers;

use App\DTOs\FiltroMovimentacaoEstoqueDTO;
use App\Http\Requests\StoreMovimentacaoRequest;
use App\Http\Requests\UpdateMovimentacaoRequest;
use App\Http\Resources\MovimentacaoResource;
use App\Models\EstoqueMovimentacao;
use App\Services\EstoqueMovimentacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

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
     * @return JsonResponse
     */
    public function store(StoreMovimentacaoRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $usuarioId = auth()->id();

        try {
            $movimentacao = $this->service->registrarMovimentacaoManual($dados, $usuarioId);
            return response()->json(new MovimentacaoResource($movimentacao), 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Exibe uma movimentação específica de um produto.
     *
     * @param EstoqueMovimentacao $movimentacao
     * @return JsonResponse
     */
    public function show(EstoqueMovimentacao $movimentacao): JsonResponse
    {
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
    public function update(UpdateMovimentacaoRequest $request, EstoqueMovimentacao $movimentacao): JsonResponse
    {
        return response()->json([
            'error' => 'Movimentações são imutáveis. Use o estorno para reverter.'
        ], 405);
    }

    /**
     * Remove uma movimentação de estoque.
     *
     * @param Produto $produto
     * @param EstoqueMovimentacao $movimentacao
     * @return JsonResponse
     */
    public function destroy(EstoqueMovimentacao $movimentacao): JsonResponse
    {
        return response()->json([
            'error' => 'Movimentações são imutáveis. Use o estorno para reverter.'
        ], 405);
    }

    /**
     * POST /v1/estoque/movimentacoes/lote
     * Registra lote de movimentações (entrada, saída ou transferência)
     */
    public function lote(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'tipo' => ['required', Rule::in(['entrada', 'saida', 'transferencia'])],

            'deposito_origem_id' => ['nullable', 'integer', 'exists:depositos,id', 'required_if:tipo,saida,transferencia'],
            'deposito_destino_id' => ['nullable', 'integer', 'exists:depositos,id', 'required_if:tipo,entrada,transferencia'],

            'observacao' => ['nullable', 'string', 'max:1000'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.variacao_id' => ['required', 'integer', 'exists:produto_variacoes,id'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1'],
        ]);

        $usuarioId = auth()->id();

        $result = $this->service->registrarMovimentacaoLote($dados, $usuarioId);
        return response()->json($result);
    }

    /**
     * POST /v1/estoque/movimentacoes/{movimentacao}/estornar
     */
    public function estornar(Request $request, EstoqueMovimentacao $movimentacao): JsonResponse
    {
        $request->validate([
            'observacao' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $estorno = $this->service->estornarMovimentacao(
                (int) $movimentacao->id,
                auth()->id(),
                $request->input('observacao')
            );

            return response()->json(new MovimentacaoResource($estorno), 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
