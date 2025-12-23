<?php

namespace App\Http\Controllers;

use App\Http\Requests\Financeiro\CategoriaFinanceiraIndexRequest;
use App\Http\Resources\CategoriaFinanceiraOptionResource;
use App\Models\CategoriaFinanceira;
use App\Services\CategoriaFinanceiraCatalogoService;
use Illuminate\Http\JsonResponse;

class CategoriaFinanceiraController extends Controller
{
    public function __construct(private CategoriaFinanceiraCatalogoService $service) {}

    public function index(CategoriaFinanceiraIndexRequest $request): JsonResponse
    {
        $f = $request->validated();
        $tree = (bool)($f['tree'] ?? false);

        // Se não for tree, devolve Resource “flat”
        if (!$tree) {
            $items = CategoriaFinanceira::query()
                ->select(['id','nome','slug','tipo','ativo','padrao','categoria_pai_id','ordem'])
                ->when(!empty($f['tipo']), fn($q) => $q->where('tipo', $f['tipo']))
                ->when(array_key_exists('ativo', $f) && $f['ativo'] !== null, fn($q) => $q->where('ativo', $f['ativo']))
                ->when(!empty($f['q']), function ($q) use ($f) {
                    $term = trim((string)$f['q']);
                    $q->where(fn($w) => $w->where('nome','like',"%{$term}%")->orWhere('slug','like',"%{$term}%"));
                })
                ->orderBy('ordem')->orderBy('nome')
                ->get();

            return response()->json([
                'data' => CategoriaFinanceiraOptionResource::collection($items),
            ]);
        }

        // tree: devolve array hierárquico
        return response()->json([
            'data' => $this->service->listar($f, true),
        ]);
    }
}
