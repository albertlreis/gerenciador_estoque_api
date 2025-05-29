<?php

namespace App\Http\Controllers;

use App\Models\Produto;
use App\Models\ProdutoVariacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProdutoVariacaoController extends Controller
{
    public function index(Produto $produto)
    {
        return response()->json($produto->variacoes);
    }

    public function store(Request $request, Produto $produto)
    {
        $validated = $request->validate([
            'referencia' => 'required|string|max:100|unique:produto_variacoes,referencia',
            'nome' => 'required|string|max:255',
            'preco' => 'required|numeric',
            'custo' => 'required|numeric',
            'peso' => 'required|numeric',
            'altura' => 'required|numeric',
            'largura' => 'required|numeric',
            'profundidade' => 'required|numeric',
            'codigo_barras' => 'nullable|string|max:100',
        ]);

        $validated['produto_id'] = $produto->id;
        $variacao = ProdutoVariacao::create($validated);

        return response()->json($variacao, 201);
    }

    public function show(Produto $produto, ProdutoVariacao $variacao)
    {
        if ($variacao->produto_id !== $produto->id) {
            return response()->json(['error' => 'Variação não pertence a este produto'], 404);
        }

        return response()->json($variacao);
    }

    public function update(Request $request, Produto $produto, ProdutoVariacao $variacao)
    {
        if ($variacao->produto_id !== $produto->id) {
            return response()->json(['error' => 'Variação não pertence a este produto'], 404);
        }

        $validated = $request->validate([
            'referencia' => 'sometimes|required|string|max:100|unique:produto_variacoes,referencia,' . $variacao->id,
            'nome' => 'sometimes|required|string|max:255',
            'preco' => 'sometimes|required|numeric',
            'custo' => 'sometimes|required|numeric',
            'peso' => 'sometimes|required|numeric',
            'altura' => 'sometimes|required|numeric',
            'largura' => 'sometimes|required|numeric',
            'profundidade' => 'sometimes|required|numeric',
            'codigo_barras' => 'nullable|string|max:100',
        ]);

        $variacao->update($validated);
        return response()->json($variacao);
    }

    public function destroy(Produto $produto, ProdutoVariacao $variacao)
    {
        if ($variacao->produto_id !== $produto->id) {
            return response()->json(['error' => 'Variação não pertence a este produto'], 404);
        }

        $variacao->delete();
        return response()->json(null, 204);
    }

    public function buscar(Request $request): JsonResponse
    {
        $query = ProdutoVariacao::query()
            ->with('produto')
//            ->limit(50)
            ->orderBy('nome');

        if ($request->filled('search')) {
            $busca = $request->input('search');
            $query->where(function ($q) use ($busca) {
                $q->where('nome', 'like', "%{$busca}%")
                    ->orWhere('referencia', 'like', "%{$busca}%")
                    ->orWhere('codigo_barras', 'like', "%{$busca}%");
            });
        }

        return response()->json(
            $query->get()->map(function ($v) {
                return [
                    'id' => $v->id,
                    'nome_completo' => $v->nome_completo,
                    'descricao' => $v->nome_completo,
                ];
            })
        );
    }

}
