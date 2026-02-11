<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProdutoMiniResource;
use App\Http\Resources\ProdutoSimplificadoResource;
use App\Http\Resources\ProdutoVariacaoResource;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Services\ProdutoVariacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class ProdutoVariacaoController extends Controller
{
    protected ProdutoVariacaoService $service;

    public function __construct(ProdutoVariacaoService $service)
    {
        $this->service = $service;
    }

    public function index(Produto $produto): AnonymousResourceCollection
    {
        $variacoes = $produto->variacoes()
            ->with(['atributos', 'produto'])
            ->get();

        return ProdutoVariacaoResource::collection($variacoes);
    }

    public function store(Request $request, Produto $produto): JsonResponse
    {
        $validated = $request->validate([
            'referencia' => 'required|string|max:100|unique:produto_variacoes,referencia',
            'preco' => 'required|numeric',
            'custo' => 'required|numeric',
            'codigo_barras' => 'nullable|string|max:100',
            'atributos' => 'nullable|array',
            'atributos.*.atributo' => 'required_with:atributos.*.valor|string|max:255',
            'atributos.*.valor' => 'required_with:atributos.*.atributo|string|max:255',
        ]);

        $validated['produto_id'] = $produto->id;
        $variacao = ProdutoVariacao::create($validated);

        $atributos = collect($validated['atributos'] ?? [])
            ->filter(fn($attr) => trim((string)($attr['atributo'] ?? '')) !== '' && trim((string)($attr['valor'] ?? '')) !== '')
            ->map(fn($attr) => [
                'atributo' => $attr['atributo'],
                'valor' => $attr['valor'],
            ])
            ->values();

        if ($atributos->isNotEmpty()) {
            $variacao->atributos()->createMany($atributos->all());
        }

        return response()->json($variacao, 201);
    }

    /**
     * Exibe os dados completos de uma variação específica.
     * Aceita view=completa|simplificada|minima
     */
    public function show(Produto $produto, ProdutoVariacao $variacao): JsonResponse
    {
        $view = request('view', 'completa');
        $variacaoCompleta = $this->service->obterVariacaoCompleta($produto->id, $variacao->id);

        return match ($view) {
            'minima' => ProdutoMiniResource::make($variacaoCompleta->produto)->response(),
            'simplificada' => ProdutoSimplificadoResource::make($variacaoCompleta->produto)->response(),
            default => ProdutoVariacaoResource::make($variacaoCompleta)->response(),
        };
    }


    public function update(Request $request, Produto $produto, ProdutoVariacaoService $service, ProdutoVariacao $variacao = null): JsonResponse
    {
        $dados = $request->all();

        if (!is_array($dados)) {
            return response()->json(['message' => 'Formato inv??lido.'], 400);
        }

        $isList = array_is_list($dados);

        try {
            if ($isList) {
                $service->atualizarLote($produto->id, $dados);
                return response()->json(['message' => 'Varia????es atualizadas com sucesso.']);
            }

            if (!$variacao) {
                return response()->json(['message' => 'Formato inv??lido.'], 400);
            }

            if ($variacao->produto_id !== $produto->id) {
                return response()->json(['error' => 'Varia????o n??o pertence a este produto'], 404);
            }

            $variacaoAtualizada = $service->atualizarIndividual($variacao, $dados);

            return response()->json($variacaoAtualizada);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
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
                    'nome_completo' => $v->nome_completo,
                    'referencia' => $v->referencia,
                    'produto_id' => $v->produto_id,
                    'produto_nome' => $v->produto?->nome,
                    'preco' => (float) ($v->preco ?? 0),
                ];
            })
        );
    }

}
