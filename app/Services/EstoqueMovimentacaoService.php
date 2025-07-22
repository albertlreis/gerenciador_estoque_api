<?php

namespace App\Services;

use App\Models\EstoqueMovimentacao;
use Illuminate\Http\Request;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Serviço responsável por aplicar regras de negócio nas movimentações de estoque.
 */
class EstoqueMovimentacaoService
{
    /**
     * Busca movimentações com base em filtros fornecidos.
     *
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function buscarComFiltros(Request $request): LengthAwarePaginator
    {
        $query = EstoqueMovimentacao::with([
            'variacao.produto', 'variacao.atributos', 'usuario',
            'depositoOrigem', 'depositoDestino'
        ]);

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->input('tipo'));
        }

        if ($request->filled('produto')) {
            $produto = $request->input('produto');
            $query->where(function ($q) use ($produto) {
                $q->whereHas('variacao.produto', function ($sub) use ($produto) {
                    $sub->where('nome', 'like', "%{$produto}%");
                })->orWhereHas('variacao', function ($sub) use ($produto) {
                    $sub->where('referencia', 'like', "%{$produto}%");
                });
            });
        }

        if ($request->filled('deposito')) {
            $query->where(function ($q) use ($request) {
                $q->where('id_deposito_origem', $request->deposito)
                    ->orWhere('id_deposito_destino', $request->deposito);
            });
        }

        if ($request->filled('periodo') && is_array($request->periodo)) {
            $query->whereBetween('data_movimentacao', [
                $request->periodo[0], $request->periodo[1]
            ]);
        }

        return $query->orderByDesc('data_movimentacao')
            ->paginate($request->input('per_page', 10));
    }
}
