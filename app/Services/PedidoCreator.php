<?php

namespace App\Services;

use App\Enums\PedidoStatus;
use App\Helpers\AuthHelper;
use App\Http\Requests\StorePedidoRequest;
use App\Models\Carrinho;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Serviço responsável pela criação de pedidos.
 */
class PedidoCreator
{
    public function __construct(
        private readonly PedidoPrazoService $pedidoPrazoService
    ) {}

    /**
     * Cria um pedido a partir do carrinho.
     *
     * @param  StorePedidoRequest $request
     * @return JsonResponse
     */
    public function criar(StorePedidoRequest $request): JsonResponse
    {
        $usuarioId = auth()->id();
        $idUsuarioFinal = $request->id_usuario;

        $query = Carrinho::with('itens')->where('id', $request->id_carrinho);

        if (!AuthHelper::hasPermissao('carrinhos.visualizar.todos')) {
            $query->where('id_usuario', $usuarioId);
        }

        $carrinho = $query->firstOrFail();

        if ($carrinho->itens->isEmpty()) {
            return response()->json(['message' => 'Carrinho está vazio.'], 422);
        }

        return DB::transaction(function () use ($request, $carrinho, $idUsuarioFinal) {
            $total = $carrinho->itens->sum('subtotal');

            $dataPedido      = Carbon::now('America/Belem');
            $prazoPadrao     = (int) config('orders.prazo_padrao_dias_uteis', 60);
            $prazoDiasUteis  = (int) ($request->input('prazo_dias_uteis') ?? $prazoPadrao);

            // 1) Cria o pedido sem data_limite (ainda)
            $pedido = Pedido::create([
                'id_cliente'          => $request->id_cliente,
                'id_usuario'          => $idUsuarioFinal,
                'id_parceiro'         => $request->id_parceiro,
                'data_pedido'         => $dataPedido,
                'valor_total'         => $total,
                'observacoes'         => $request->observacoes,
                'prazo_dias_uteis'    => $prazoDiasUteis,
                // 'data_limite_entrega' será definida pelo service
            ]);

            // 2) Itens
            foreach ($carrinho->itens as $item) {
                PedidoItem::create([
                    'id_pedido'      => $pedido->id,
                    'id_variacao'    => $item->id_variacao,
                    'quantidade'     => $item->quantidade,
                    'preco_unitario' => $item->preco_unitario,
                    'subtotal'       => $item->subtotal,
                ]);
            }

            // 3) Status inicial
            PedidoStatusHistorico::create([
                'pedido_id'   => $pedido->id,
                'status'      => PedidoStatus::PEDIDO_CRIADO,
                'data_status' => Carbon::now('America/Belem'),
                'usuario_id'  => $idUsuarioFinal,
            ]);

            // 4) Define a data limite via Service centralizado
            //    (usa data_pedido e prazo_dias_uteis já persistidos)
            $this->pedidoPrazoService->definirDataLimite($pedido);

            // 5) Limpa e finaliza carrinho
            $carrinho->itens()->delete();
            $carrinho->update(['status' => 'finalizado']);

            return response()->json([
                'message' => 'Pedido criado com sucesso.',
                'pedido'  => $pedido->load('itens.variacao'),
            ], 201);
        });
    }
}
