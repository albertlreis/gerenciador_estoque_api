<?php

namespace App\Http\Controllers;

use App\Models\Produto;
use App\Models\EstoqueMovimentacao;
use Illuminate\Http\Request;

class EstoqueMovimentacaoController extends Controller
{
    public function index(Produto $produto)
    {
        // Retorna todas as movimentações que pertencem ao produto.
        $movimentacoes = EstoqueMovimentacao::where('id_produto', $produto->id)->get();
        return response()->json($movimentacoes);
    }

    public function store(Request $request, Produto $produto)
    {
        $validated = $request->validate([
            'id_deposito_origem'  => 'nullable|exists:depositos,id',
            'id_deposito_destino' => 'nullable|exists:depositos,id',
            'tipo'                => 'required|string|max:50',
            'quantidade'          => 'required|integer',
            'observacao'          => 'nullable|string',
            'data_movimentacao'   => 'nullable|date',
        ]);

        // Em vez de associar à variação, associamos ao produto.
        $validated['id_produto'] = $produto->id;
        $movimentacao = EstoqueMovimentacao::create($validated);
        return response()->json($movimentacao, 201);
    }

    public function show(Produto $produto, EstoqueMovimentacao $movimentacao)
    {
        if ($movimentacao->id_produto !== $produto->id) {
            return response()->json(['error' => 'Movimentação não pertence a este produto'], 404);
        }
        return response()->json($movimentacao);
    }

    public function update(Request $request, Produto $produto, EstoqueMovimentacao $movimentacao)
    {
        if ($movimentacao->id_produto !== $produto->id) {
            return response()->json(['error' => 'Movimentação não pertence a este produto'], 404);
        }

        $validated = $request->validate([
            'id_deposito_origem'  => 'nullable|exists:depositos,id',
            'id_deposito_destino' => 'nullable|exists:depositos,id',
            'tipo'                => 'sometimes|required|string|max:50',
            'quantidade'          => 'sometimes|required|integer',
            'observacao'          => 'nullable|string',
            'data_movimentacao'   => 'nullable|date',
        ]);

        $movimentacao->update($validated);
        return response()->json($movimentacao);
    }

    public function destroy(Produto $produto, EstoqueMovimentacao $movimentacao)
    {
        if ($movimentacao->id_produto !== $produto->id) {
            return response()->json(['error' => 'Movimentação não pertence a este produto'], 404);
        }

        $movimentacao->delete();
        return response()->json(null, 204);
    }
}
