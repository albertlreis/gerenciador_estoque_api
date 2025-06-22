<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCarrinhoItemRequest;
use App\Models\Carrinho;
use App\Models\CarrinhoItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Helpers\AuthHelper;

/**
 * Controller responsável por gerenciar os itens do carrinho.
 */
class CarrinhoItemController extends Controller
{
    /**
     * Adiciona ou atualiza um item no carrinho.
     *
     * @param StoreCarrinhoItemRequest $request
     * @return JsonResponse
     */
    public function store(StoreCarrinhoItemRequest $request): JsonResponse
    {
        $query = Carrinho::where('id', $request->id_carrinho);

        if (!AuthHelper::hasPermissao('carrinhos.visualizar.todos')) {
            $query->where('id_usuario', Auth::id());
        }

        $carrinho = $query->firstOrFail();

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

    /**
     * Remove um item do carrinho.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $item = CarrinhoItem::whereHas('carrinho', function ($q) {
            if (!AuthHelper::hasPermissao('carrinhos.visualizar.todos')) {
                $q->where('id_usuario', Auth::id());
            }
        })->findOrFail($id);

        $item->delete();

        return response()->json(['message' => 'Item removido com sucesso.']);
    }

    /**
     * Limpa todos os itens de um carrinho.
     *
     * @param int $idCarrinho
     * @return JsonResponse
     */
    public function clear(int $idCarrinho): JsonResponse
    {
        $query = Carrinho::where('id', $idCarrinho);

        if (!AuthHelper::hasPermissao('carrinhos.visualizar.todos')) {
            $query->where('id_usuario', Auth::id());
        }

        $carrinho = $query->firstOrFail();

        $carrinho->itens()->delete();

        return response()->json(['message' => 'Carrinho limpo com sucesso.']);
    }

    /**
     * Atualiza o depósito vinculado a um item do carrinho.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function atualizarDeposito(Request $request): JsonResponse
    {
        $request->validate([
            'id_carrinho_item' => 'required|exists:carrinho_itens,id',
            'id_deposito'      => 'nullable|exists:depositos,id',
        ]);

        $item = CarrinhoItem::where('id', $request->id_carrinho_item)
            ->whereHas('carrinho', function ($q) {
                if (!AuthHelper::hasPermissao('carrinhos.visualizar.todos')) {
                    $q->where('id_usuario', Auth::id());
                }
            })
            ->firstOrFail();

        $item->id_deposito = $request->id_deposito;
        $item->save();

        return response()->json(['success' => true]);
    }
}
