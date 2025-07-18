<?php

namespace App\Services;

use App\Http\Requests\StorePedidoRequest;
use App\Http\Resources\PedidoCompletoResource;
use App\Http\Resources\PedidoListCollection;
use App\Models\Pedido;
use App\Repositories\PedidoRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Serviço principal para operações de pedidos.
 */
class PedidoService
{
    protected PedidoRepository $pedidoRepository;
    protected PedidoCreator $pedidoCreator;

    /**
     * Injeta as dependências do serviço.
     *
     * @param PedidoRepository $pedidoRepository
     * @param PedidoCreator $pedidoCreator
     */
    public function __construct(
        PedidoRepository $pedidoRepository,
        PedidoCreator $pedidoCreator
    ) {
        $this->pedidoRepository = $pedidoRepository;
        $this->pedidoCreator = $pedidoCreator;
    }

    /**
     * Lista pedidos com filtros, paginação e ordenação.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function listarPedidos(Request $request): JsonResponse
    {
        $query = $this->pedidoRepository->comFiltros($request);

        $ordenarPor = $request->get('ordenarPor', 'data_pedido');
        $ordem = $request->get('ordem', 'desc');
        $perPage = (int) $request->get('per_page', 10);

        $paginado = $query->orderBy($ordenarPor, $ordem)
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json(new PedidoListCollection($paginado));
    }

    /**
     * Cria um pedido com base num carrinho existente.
     *
     * @param StorePedidoRequest $request
     * @return JsonResponse
     */
    public function criarPedido(StorePedidoRequest $request): JsonResponse
    {
        return $this->pedidoCreator->criar($request);
    }

    /**
     * Retorna os dados completos de um pedido.
     *
     * @param int $pedidoId
     * @return PedidoCompletoResource
     */
    public function obterPedidoCompleto(int $pedidoId): PedidoCompletoResource
    {
        $pedido = Pedido::with([
            'cliente:id,nome,email,telefone',
            'parceiro:id,nome',
            'usuario:id,nome',
            'itens.variacao.produto.imagens',
            'itens.variacao.atributos',
            'historicoStatus.usuario:id,nome',
            'devolucoes.itens.pedidoItem.variacao.produto',
            'devolucoes.itens.trocaItens.variacaoNova.produto',
            'devolucoes.credito',
        ])->findOrFail($pedidoId);

        return new PedidoCompletoResource($pedido);
    }
}
