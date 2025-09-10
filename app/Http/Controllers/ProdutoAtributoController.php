<?php

namespace App\Http\Controllers;

use App\Models\ProdutoVariacaoAtributo;
use App\Services\ProdutoAtributoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProdutoAtributoController extends Controller
{
    public function __construct(protected ProdutoAtributoService $service)
    {
    }

    /**
     * Retorna todos os atributos únicos e seus valores.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $atributos = ProdutoVariacaoAtributo::query()
            ->join('produto_variacoes', 'produto_variacoes.id', '=', 'produto_variacao_atributos.id_variacao')
            ->join('produtos', 'produtos.id', '=', 'produto_variacoes.produto_id')
            ->where('produtos.ativo', true)
            ->select('produto_variacao_atributos.atributo', 'produto_variacao_atributos.valor')
            ->get()
            ->groupBy('atributo')
            ->map(fn($items) => $items->pluck('valor')->unique()->values());

        return response()->json($atributos);
    }

    /**
     * GET /atributos/sugestoes?q=cor
     * Retorna lista de nomes de atributos já usados (normalizados).
     */
    public function nomes(Request $request): JsonResponse
    {
        $q = $request->string('q')->toString() ?: null;
        return response()->json($this->service->sugerirNomes($q));
    }

    /**
     * GET /atributos/{nome}/valores?q=vermelho
     * Retorna lista de valores para um nome de atributo específico.
     */
    public function valores(Request $request, string $nome): JsonResponse
    {
        $q = $request->string('q')->toString() ?: null;
        return response()->json($this->service->sugerirValores($nome, $q));
    }
}
