<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCarrinhoItemRequest;
use App\Models\Carrinho;
use App\Models\CarrinhoItem;
use App\Models\ProdutoVariacaoOutlet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Helpers\AuthHelper;
use Illuminate\Validation\ValidationException;

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

        if (!AuthHelper::podeVisualizarCarrinhosDeTodos()) {
            $query->where('id_usuario', Auth::id());
        }

        $carrinho = $query->firstOrFail();

        $this->validarOutletSelecionado($request);

        $item = CarrinhoItem::updateOrCreate(
            [
                'id_carrinho' => $carrinho->id,
                'id_variacao' => $request->id_variacao,
                'outlet_id' => $request->outlet_id ?? null,
            ],
            [
                'quantidade'     => $request->quantidade,
                'preco_unitario' => $request->preco_unitario,
                'subtotal'       => $request->quantidade * $request->preco_unitario,
                'outlet_id'      => $request->outlet_id ?? null,
            ]
        );

        return response()->json($item, 201);
    }

    private function validarOutletSelecionado(StoreCarrinhoItemRequest $request): void
    {
        if (!$request->filled('outlet_id')) {
            return;
        }

        /** @var ProdutoVariacaoOutlet|null $outlet */
        $outlet = ProdutoVariacaoOutlet::query()->find($request->outlet_id);

        if (!$outlet || (int) $outlet->produto_variacao_id !== (int) $request->id_variacao) {
            throw ValidationException::withMessages([
                'outlet_id' => ['O outlet selecionado nao pertence a variacao informada.'],
            ]);
        }

        if ((int) $outlet->quantidade_restante <= 0) {
            throw ValidationException::withMessages([
                'outlet_id' => ['O outlet selecionado nao possui saldo disponivel.'],
            ]);
        }

        if ((int) $request->quantidade > (int) $outlet->quantidade_restante) {
            throw ValidationException::withMessages([
                'quantidade' => [
                    "Quantidade indisponivel para este outlet. Saldo atual: {$outlet->quantidade_restante}.",
                ],
            ]);
        }
    }

    /**
     * Remove um item do carrinho.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $carrinho, int $item): JsonResponse
    {
        $item = CarrinhoItem::where('id_carrinho', $carrinho)
            ->whereHas('carrinho', function ($q) {
            if (!AuthHelper::podeVisualizarCarrinhosDeTodos()) {
                $q->where('id_usuario', Auth::id());
            }
            })
            ->findOrFail($item);

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

        if (!AuthHelper::podeVisualizarCarrinhosDeTodos()) {
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
    public function atualizarDeposito(Request $request, int $carrinho): JsonResponse
    {
        $validated = $request->validate([
            'id_carrinho_item' => 'required|integer|exists:carrinho_itens,id',
            'id_deposito'      => 'nullable|integer|exists:depositos,id',
        ]);

        $this->atualizarDepositosDoCarrinho($carrinho, [[
            'id_carrinho_item' => (int) $validated['id_carrinho_item'],
            'id_deposito' => $validated['id_deposito'] ?? null,
        ]]);

        return response()->json(['success' => true]);
    }

    /**
     * Atualiza o deposito vinculado a varios itens do carrinho.
     *
     * @param Request $request
     * @param int $carrinho
     * @return JsonResponse
     */
    public function atualizarDepositos(Request $request, int $carrinho): JsonResponse
    {
        $validated = $request->validate([
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.id_carrinho_item' => ['required', 'integer', 'distinct', 'exists:carrinho_itens,id'],
            'itens.*.id_deposito' => ['nullable', 'integer', 'exists:depositos,id'],
        ]);

        $updated = $this->atualizarDepositosDoCarrinho($carrinho, $validated['itens']);

        return response()->json(['success' => true, 'updated' => $updated]);
    }

    private function carrinhoAutorizado(int $carrinho): Carrinho
    {
        $query = Carrinho::where('id', $carrinho);

        if (!AuthHelper::podeVisualizarCarrinhosDeTodos()) {
            $query->where('id_usuario', Auth::id());
        }

        return $query->firstOrFail();
    }

    /**
     * @param array<int,array{id_carrinho_item:int,id_deposito?:int|null}> $itensPayload
     */
    private function atualizarDepositosDoCarrinho(int $carrinhoId, array $itensPayload): int
    {
        $carrinho = $this->carrinhoAutorizado($carrinhoId);
        $ids = collect($itensPayload)
            ->pluck('id_carrinho_item')
            ->map(fn ($id) => (int) $id)
            ->values();

        $itens = $carrinho->itens()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($itensPayload as $index => $payload) {
            if (!$itens->has((int) $payload['id_carrinho_item'])) {
                throw ValidationException::withMessages([
                    "itens.$index.id_carrinho_item" => ['Item nao pertence a este carrinho.'],
                ]);
            }
        }

        DB::transaction(function () use ($itensPayload, $itens) {
            foreach ($itensPayload as $payload) {
                /** @var CarrinhoItem $item */
                $item = $itens->get((int) $payload['id_carrinho_item']);
                $item->id_deposito = $payload['id_deposito'] ?? null;
                $item->save();
            }
        });

        return count($itensPayload);
    }
}
