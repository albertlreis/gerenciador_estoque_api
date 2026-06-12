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
        /**
         * 🔍 Busca rápida (auto-complete)
         * Quando vier parâmetro "q", retorna resultados leves para seleção de pedidos.
         */
        if ($request->filled('q')) {
            $termo = trim($request->get('q'));

            $resultados = Pedido::query()
                ->with(['cliente:id,nome'])
                ->select(['id', 'numero_externo', 'id_cliente', 'data_pedido'])
                ->when($termo !== '', function ($q) use ($termo) {
                    $q->where('numero_externo', 'like', "%{$termo}%")
                        ->orWhereHas('cliente', fn($sub) =>
                        $sub->where('nome', 'like', "%{$termo}%")
                        );
                })
                ->orderByDesc('data_pedido')
                ->limit(20)
                ->get()
                ->map(function ($p) {
                    $numero = $p->numero_externo ?: "ID {$p->id}";
                    $cliente = $p->cliente?->nome ?? '-';
                    $data = $p->data_pedido?->format('d/m/Y');
                    $detalhes = array_filter(["ID {$p->id}", $cliente, $data]);

                    return [
                        'id' => $p->id,
                        'numero' => $p->numero_externo,
                        'cliente' => $cliente,
                        'data_pedido' => $data,
                        'label' => "#{$numero} - " . implode(' - ', $detalhes),
                    ];
                });

            return response()->json($resultados);
        }

        /**
         * 🔁 Listagem padrão com paginação e filtros
         */
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
     * @throws \Illuminate\Validation\ValidationException
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
            'fornecedor:id,nome,cnpj',
            'usuario:id,nome',
            'statusAtual',
            'itens.variacao.produto.imagens',
            'itens.variacao.atributos',
            'historicoStatus.usuario:id,nome',
            'entregaItens.variacao.produto',
            'entregaItens.variacao.atributos',
            'entregaItens.depositoDestino:id,nome',
            'devolucoes.itens.pedidoItem.variacao.produto',
            'devolucoes.itens.trocaItens.variacaoNova.produto',
            'devolucoes.credito',
        ])->findOrFail($pedidoId);

        return new PedidoCompletoResource($pedido);
    }
}
