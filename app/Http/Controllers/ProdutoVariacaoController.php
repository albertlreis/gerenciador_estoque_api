<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProdutoVariacaoResource;
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
        $variacoes = $produto->variacoes()
            ->with(['atributos', 'produto'])
            ->get();

        return ProdutoVariacaoResource::collection($variacoes);
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
            ->with(['produto', 'atributos'])
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $busca = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $request->input('search')));

            $query->where(function ($q) use ($busca) {
                $q->whereRaw("LOWER(referencia) COLLATE utf8mb4_general_ci LIKE ?", ["%{$busca}%"])
                    ->orWhereRaw("LOWER(codigo_barras) COLLATE utf8mb4_general_ci LIKE ?", ["%{$busca}%"])
                    ->orWhereHas('produto', function ($qp) use ($busca) {
                        $qp->whereRaw("LOWER(nome) COLLATE utf8mb4_general_ci LIKE ?", ["%{$busca}%"]);
                    });
            });
        }

        $variacoes = $query->get();

        return response()->json(
            $variacoes->map(function ($v) {
                return [
                    'id' => $v->id,
                    'nome_completo' => $v->nome_completo
                ];
            })
        );
    }

}
