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

/**
 * Serviço responsável pela criação de pedidos.
 */
class PedidoCreator
{
    /**
     * Cria um novo pedido a partir do carrinho.
     *
     * @param StorePedidoRequest $request
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

            $pedido = Pedido::create([
                'id_cliente' => $request->id_cliente,
                'id_usuario' => $idUsuarioFinal,
                'id_parceiro' => $request->id_parceiro,
                'data_pedido' => now(),
                'valor_total' => $total,
                'observacoes' => $request->observacoes,
            ]);

            foreach ($carrinho->itens as $item) {
                PedidoItem::create([
                    'id_pedido' => $pedido->id,
                    'id_variacao' => $item->id_variacao,
                    'quantidade' => $item->quantidade,
                    'preco_unitario' => $item->preco_unitario,
                    'subtotal' => $item->subtotal,
                ]);
            }

            PedidoStatusHistorico::create([
                'pedido_id' => $pedido->id,
                'status' => PedidoStatus::PEDIDO_CRIADO,
                'data_status' => now(),
                'usuario_id' => $idUsuarioFinal,
            ]);

            $carrinho->itens()->delete();
            $carrinho->update(['status' => 'finalizado']);

            return response()->json([
                'message' => 'Pedido criado com sucesso.',
                'pedido' => $pedido->load('itens.variacao'),
            ], 201);
        });
    }
}
