<?php

namespace App\Http\Controllers;

use App\Models\CentroCusto;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CentroCustoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tipo = $request->string('tipo')->toString(); // opcional: se quiser filtrar por meta
        $q = CentroCusto::query()->ativos()->orderBy('ordem')->orderBy('nome');

        return response()->json([
            'data' => $q->get()->map(fn($c) => [
                'id' => $c->id,
                'nome' => $c->nome,
                'slug' => $c->slug,
                'pai_id' => $c->centro_custo_pai_id,
                'ativo' => (bool)$c->ativo,
                'padrao' => (bool)$c->padrao,
            ])
        ]);
    }
}
