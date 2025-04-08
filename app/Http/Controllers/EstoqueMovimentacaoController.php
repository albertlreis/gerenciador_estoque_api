<?php

namespace App\Http\Controllers;

use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\EstoqueMovimentacao;
use Illuminate\Http\Request;

class EstoqueMovimentacaoController extends Controller
{
    public function index(Produto $produto, ProdutoVariacao $variacao)
    {
        if ($variacao->id_produto !== $produto->id) {
            return response()->json(['error' => 'Variação não pertence a este produto'], 404);
        }
        return response()->json($variacao->movimentacoes);
    }

    public function store(Request $request, Produto $produto, ProdutoVariacao $variacao)
    {
        if ($variacao->id_produto !== $produto->id) {
            return response()->json(['error' => 'Variação não pertence a este produto'], 404);
        }

        $validated = $request->validate([
            'id_deposito_origem'  => 'nullable|exists:depositos,id',
            'id_deposito_destino' => 'nullable|exists:depositos,id',
            'tipo'                => 'required|string|max:50',
            'quantidade'          => 'required|integer',
            'observacao'          => 'nullable|string',
            'data_movimentacao'   => 'nullable|date',
        ]);

        $validated['id_variacao'] = $variacao->id;
        $movimentacao = EstoqueMovimentacao::create($validated);
        return response()->json($movimentacao, 201);
    }

    public function show(Produto $produto, ProdutoVariacao $variacao, EstoqueMovimentacao $movimentacao)
    {
        if ($variacao->id_produto !== $produto->id || $movimentacao->id_variacao !== $variacao->id) {
            return response()->json(['error' => 'Movimentação não pertence a esta variação ou produto'], 404);
        }
        return response()->json($movimentacao);
    }

    public function update(Request $request, Produto $produto, ProdutoVariacao $variacao, EstoqueMovimentacao $movimentacao)
    {
        if ($variacao->id_produto !== $produto->id || $movimentacao->id_variacao !== $variacao->id) {
            return response()->json(['error' => 'Movimentação não pertence a esta variação ou produto'], 404);
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

    public function destroy(Produto $produto, ProdutoVariacao $variacao, EstoqueMovimentacao $movimentacao)
    {
        if ($variacao->id_produto !== $produto->id || $movimentacao->id_variacao !== $variacao->id) {
            return response()->json(['error' => 'Movimentação não pertence a esta variação ou produto'], 404);
        }

        $movimentacao->delete();
        return response()->json(null, 204);
    }
}
