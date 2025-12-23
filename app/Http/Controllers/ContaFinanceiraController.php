<?php

namespace App\Http\Controllers;

use App\Http\Requests\Financeiro\ContaFinanceiraIndexRequest;
use App\Http\Resources\ContaFinanceiraOptionResource;
use App\Models\ContaFinanceira;
use Illuminate\Http\JsonResponse;

class ContaFinanceiraController extends Controller
{
    public function index(ContaFinanceiraIndexRequest $request): JsonResponse
    {
        $f = $request->validated();

        $items = ContaFinanceira::query()
            ->select(['id','nome','slug','tipo','ativo','padrao','moeda'])
            ->when(!empty($f['tipo']), fn($q) => $q->where('tipo', $f['tipo']))
            ->when(array_key_exists('ativo', $f) && $f['ativo'] !== null, fn($q) => $q->where('ativo', $f['ativo']))
            ->when(!empty($f['q']), function ($q) use ($f) {
                $term = trim((string)$f['q']);
                $q->where(fn($w) => $w->where('nome','like',"%{$term}%")->orWhere('slug','like',"%{$term}%"));
            })
            ->orderByDesc('padrao')
            ->orderBy('nome')
            ->get();

        return response()->json([
            'data' => ContaFinanceiraOptionResource::collection($items),
        ]);
    }
}
