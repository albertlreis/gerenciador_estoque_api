<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCarrinhoItemRequest;
use App\Models\Carrinho;
use App\Models\CarrinhoItem;
use Illuminate\Support\Facades\Auth;

class CarrinhoItemController extends Controller
{
    public function store(StoreCarrinhoItemRequest $request)
    {
        $carrinho = Carrinho::where('id', $request->id_carrinho)
            ->where('id_usuario', Auth::id())
            ->firstOrFail();

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

    public function destroy($id)
    {
        $item = CarrinhoItem::whereHas('carrinho', function ($q) {
            $q->where('id_usuario', Auth::id());
        })->findOrFail($id);

        $item->delete();

        return response()->json(['message' => 'Item removido com sucesso.']);
    }

    public function clear($idCarrinho)
    {
        $carrinho = Carrinho::where('id', $idCarrinho)
            ->where('id_usuario', Auth::id())
            ->firstOrFail();

        $carrinho->itens()->delete();

        return response()->json(['message' => 'Carrinho limpo com sucesso.']);
    }
}
