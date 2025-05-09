<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePedidoRequest;
use App\Http\Requests\UpdatePedidoStatusRequest;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Carrinho;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Controller responsável por criar e gerenciar pedidos.
 */
class PedidoController extends Controller
{
    public function index()
    {
        $pedidos = Pedido::with(['cliente', 'usuario', 'parceiro'])->latest()->get();
        return response()->json($pedidos);
    }

    public function store(StorePedidoRequest $request)
    {
        $usuarioId = Auth::id();

        $carrinho = Carrinho::where('id_usuario', $usuarioId)->with('itens')->first();

        if (!$carrinho || $carrinho->itens->isEmpty()) {
            return response()->json(['message' => 'Carrinho vazio.'], 422);
        }

        return DB::transaction(function () use ($request, $carrinho, $usuarioId) {
            $total = $carrinho->itens->sum('subtotal');

            $pedido = Pedido::create([
                'id_cliente'   => $request->id_cliente,
                'id_usuario'   => $usuarioId,
                'id_parceiro'  => $request->id_parceiro,
                'data_pedido'  => now(),
                'status'       => 'confirmado',
                'valor_total'  => $total,
                'observacoes'  => $request->observacoes,
            ]);

            foreach ($carrinho->itens as $item) {
                PedidoItem::create([
                    'id_pedido'      => $pedido->id,
                    'id_variacao'    => $item->id_variacao,
                    'quantidade'     => $item->quantidade,
                    'preco_unitario' => $item->preco_unitario,
                    'subtotal'       => $item->subtotal,
                ]);
            }

            // Limpa o carrinho após finalizar o pedido
            $carrinho->itens()->delete();

            return response()->json([
                'message' => 'Pedido criado com sucesso.',
                'pedido'  => $pedido->load('itens.variacao'),
            ], 201);
        });
    }

    public function show($id)
    {
        $pedido = Pedido::with(['cliente', 'usuario', 'parceiro', 'itens.variacao'])->findOrFail($id);
        return response()->json($pedido);
    }

    public function updateStatus(UpdatePedidoStatusRequest $request, $id)
    {
        $pedido = Pedido::findOrFail($id);
        $pedido->update(['status' => $request->status]);

        return response()->json(['message' => 'Status atualizado com sucesso.', 'pedido' => $pedido]);
    }
}
