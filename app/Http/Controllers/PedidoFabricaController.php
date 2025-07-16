<?php

namespace App\Http\Controllers;

use App\Models\PedidoFabrica;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PedidoFabricaController extends Controller
{
    /**
     * Lista todos os pedidos para fábrica com itens e filtros opcionais.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PedidoFabrica::with('itens.variacao.produto');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderByDesc('created_at')->get());
    }

    /**
     * Cria um novo pedido para fábrica com seus itens.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'data_previsao_entrega' => 'nullable|date',
            'observacoes' => 'nullable|string',
            'itens' => 'required|array|min:1',
            'itens.*.produto_variacao_id' => 'required|exists:produto_variacoes,id',
            'itens.*.quantidade' => 'required|integer|min:1',
            'itens.*.pedido_venda_id' => 'nullable|exists:pedidos,id',
            'itens.*.observacoes' => 'nullable|string',
        ]);

        $pedido = PedidoFabrica::create([
            'data_previsao_entrega' => $data['data_previsao_entrega'] ?? null,
            'observacoes' => $data['observacoes'] ?? null,
            'status' => 'pendente',
        ]);

        foreach ($data['itens'] as $item) {
            $pedido->itens()->create([
                'produto_variacao_id' => $item['produto_variacao_id'],
                'quantidade' => $item['quantidade'],
                'pedido_venda_id' => $item['pedido_venda_id'] ?? null,
                'observacoes' => $item['observacoes'] ?? null,
            ]);
        }

        return response()->json($pedido->load('itens.variacao.produto'), 201);
    }

    /**
     * Atualiza o status do pedido para fábrica.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:pendente,produzindo,entregue,cancelado',
        ]);

        $pedido = PedidoFabrica::findOrFail($id);
        $pedido->status = $request->status;
        $pedido->save();

        return response()->json(['message' => 'Status atualizado com sucesso.']);
    }

    /**
     * Retorna os dados completos de um pedido para fábrica.
     */
    public function show(int $id): JsonResponse
    {
        $pedido = PedidoFabrica::with('itens.variacao.produto')->findOrFail($id);
        return response()->json($pedido);
    }

    /**
     * Remove um pedido para fábrica (se ainda estiver pendente).
     */
    public function destroy(int $id): JsonResponse
    {
        $pedido = PedidoFabrica::findOrFail($id);

        if ($pedido->status !== 'pendente') {
            return response()->json(['error' => 'Só é possível excluir pedidos pendentes.'], 422);
        }

        $pedido->delete();

        return response()->json(['message' => 'Pedido removido com sucesso.']);
    }
}
