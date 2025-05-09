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
        $atributos = ProdutoVariacaoAtributo::select('atributo', 'valor')
            ->get()
            ->groupBy('atributo')
            ->map(function ($items) {
                return $items->pluck('valor')->unique()->values();
            });

        return response()->json($atributos);
    }
}
