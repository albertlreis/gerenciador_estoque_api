<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\ProdutoVariacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PedidoItemController extends Controller
{
    public function index(Pedido $pedido)
    {
        return response()->json($pedido->itens);
    }

    public function store(Request $request, Pedido $pedido)
    {
        $validated = $request->validate([
            'id_produto'     => 'nullable|exists:produtos,id',
            'id_variacao'    => 'nullable|exists:produto_variacoes,id',
            'quantidade'     => 'required|integer',
            'preco_unitario' => 'required|numeric',
            'id_deposito'    => 'nullable|exists:depositos,id',
            'observacoes'    => 'nullable|string|max:1000',
        ]);

        $validated['id_variacao'] = $this->resolverVariacaoId($validated, 'id_variacao');
        $validated['subtotal'] = (float) $validated['quantidade'] * (float) $validated['preco_unitario'];
        $validated['id_pedido'] = $pedido->id;
        $item = PedidoItem::create($validated);
        return response()->json($item, 201);
    }

    public function show(Pedido $pedido, PedidoItem $item)
    {
        if ($item->id_pedido !== $pedido->id) {
            return response()->json(['error' => 'Item não pertence a este pedido'], 404);
        }
        return response()->json($item);
    }

    public function update(Request $request, Pedido $pedido, PedidoItem $item)
    {
        if ($item->id_pedido !== $pedido->id) {
            return response()->json(['error' => 'Item não pertence a este pedido'], 404);
        }

        $validated = $request->validate([
            'id_produto'     => 'nullable|integer|exists:produtos,id',
            'id_variacao'    => 'nullable|integer|exists:produto_variacoes,id',
            'quantidade'     => 'sometimes|required|integer',
            'preco_unitario' => 'sometimes|required|numeric',
            'id_deposito'    => 'nullable|exists:depositos,id',
            'observacoes'    => 'nullable|string|max:1000',
        ]);

        if (array_key_exists('id_produto', $validated) || array_key_exists('id_variacao', $validated)) {
            $validated['id_variacao'] = $this->resolverVariacaoId($validated, 'id_variacao');
        }

        $quantidade = array_key_exists('quantidade', $validated)
            ? (int) $validated['quantidade']
            : (int) $item->quantidade;
        $preco = array_key_exists('preco_unitario', $validated)
            ? (float) $validated['preco_unitario']
            : (float) $item->preco_unitario;

        $validated['subtotal'] = $quantidade * $preco;

        $item->update($validated);
        return response()->json($item);
    }

    public function destroy(Pedido $pedido, PedidoItem $item)
    {
        if ($item->id_pedido !== $pedido->id) {
            return response()->json(['error' => 'Item não pertence a este pedido'], 404);
        }

        $item->delete();
        return response()->json(null, 204);
    }

    /**
     * Libera a entrega de um item pendente, marcando a data e disparando movimentação (futura).
     */
    public function liberarEntrega(int $id, Request $request): JsonResponse
    {
        $item = PedidoItem::findOrFail($id);

        if (!$item->is_entrega_pendente) {
            return response()->json(['message' => 'Item não está pendente de entrega.'], 400);
        }

        $item->data_liberacao_entrega = Carbon::now();
        $item->observacao_entrega_pendente = $request->input('observacao') ?? null;
        $item->save();

        return response()->json(['message' => 'Entrega liberada com sucesso.']);
    }

    /**
     * Lista global de itens de pedidos, com opção de filtro por entrega pendente.
     */
    public function indexGlobal(Request $request): JsonResponse
    {
        $query = PedidoItem::with([
            'pedido.cliente',
            'variacao.produto',
            'variacao.atributos'
        ]);

        if ($request->boolean('entrega_pendente')) {
            $query->where('entrega_pendente', true)
                ->whereNull('data_liberacao_entrega');
        }

        return response()->json($query->get());
    }

    /**
     * Resolve a variação a partir de id_variacao ou id_produto.
     *
     * @param array<string,mixed> $payload
     * @param string $field
     * @return int
     */
    private function resolverVariacaoId(array $payload, string $field): int
    {
        if (!empty($payload['id_variacao'])) {
            return (int) $payload['id_variacao'];
        }

        if (!empty($payload['id_produto'])) {
            $variacao = ProdutoVariacao::query()
                ->where('produto_id', (int) $payload['id_produto'])
                ->orderBy('id')
                ->first();

            if ($variacao) {
                return (int) $variacao->id;
            }
        }

        throw ValidationException::withMessages([
            $field => ['Selecione uma variação válida.'],
        ]);
    }
}
