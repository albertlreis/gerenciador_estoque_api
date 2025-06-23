<?php

namespace App\Http\Controllers;

use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Services\ProdutoVariacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

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
            'preco' => 'required|numeric',
            'custo' => 'required|numeric',
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

    public function update(Request $request, int $produtoId, ProdutoVariacaoService $service): JsonResponse
    {
        $dados = $request->all();

        if (!is_array($dados)) {
            return response()->json(['message' => 'Formato inválido.'], 400);
        }

        try {
            $service->atualizarLote($produtoId, $dados);
            return response()->json(['message' => 'Variações atualizadas com sucesso.']);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Erro inesperado.'], 500);
        }
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
