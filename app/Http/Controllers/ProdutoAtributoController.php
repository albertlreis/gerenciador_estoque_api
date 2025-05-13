<?php

namespace App\Http\Controllers;

use App\Models\ProdutoVariacaoAtributo;
use Illuminate\Http\JsonResponse;

class ProdutoAtributoController extends Controller
{
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
}
