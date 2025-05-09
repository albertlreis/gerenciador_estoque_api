<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCarrinhoItemRequest;
use App\Models\Carrinho;
use App\Models\CarrinhoItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Controller responsável por gerenciar o carrinho de compras do usuário logado (vendedor).
 */
class CarrinhoController extends Controller
{
    public function index()
    {
        $carrinho = Carrinho::firstOrCreate(['id_usuario' => Auth::id()]);
        $carrinho->load('itens.variacao');

        return response()->json($carrinho);
    }

    public function store(StoreCarrinhoItemRequest $request)
    {
        $carrinho = Carrinho::firstOrCreate(['id_usuario' => Auth::id()]);

        $item = CarrinhoItem::updateOrCreate(
            [
                'id_carrinho' => $carrinho->id,
                'id_variacao' => $request->id_variacao
            ],
            [
                'quantidade'     => $request->quantidade,
                'preco_unitario' => $request->preco_unitario,
                'subtotal'       => $request->quantidade * $request->preco_unitario,
            ]
        );

        return response()->json($item, 201);
    }

    public function destroy($itemId)
    {
        $item = CarrinhoItem::whereHas('carrinho', function ($q) {
            $q->where('id_usuario', Auth::id());
        })->findOrFail($itemId);

        $item->delete();

        return response()->json(['message' => 'Item removido com sucesso.']);
    }

    public function clear()
    {
        $carrinho = Carrinho::where('id_usuario', Auth::id())->first();

        if ($carrinho) {
            $carrinho->itens()->delete();
        }

        return response()->json(['message' => 'Carrinho limpo com sucesso.']);
    }
}
