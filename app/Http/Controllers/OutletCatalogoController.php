<?php

namespace App\Http\Controllers;

use App\Models\OutletMotivo;
use App\Models\OutletFormaPagamento;
use App\Services\OutletItensQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutletCatalogoController extends Controller{
    public function __construct(private readonly OutletItensQueryService $itensQuery)
    {
    }

    public function itens(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:255'],
            'id_categoria' => ['nullable', 'array'],
            'id_categoria.*' => ['integer', 'exists:categorias,id'],
        ]);

        $paginator = $this->itensQuery->paginate($request);

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($outlet) => $this->itensQuery->serialize($outlet)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function motivos(){
        return response()->json(
            OutletMotivo::query()->where('ativo',true)->orderBy('nome')->get(['id','slug','nome'])
        );
    }
    public function formas(){
        return response()->json(
            OutletFormaPagamento::query()->where('ativo',true)->orderBy('nome')
                ->get(['id','slug','nome','percentual_desconto_default','max_parcelas_default'])
        );
    }
}
