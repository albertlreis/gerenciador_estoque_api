<?php

namespace App\Services;

use App\DTOs\FiltroMovimentacaoEstoqueDTO;
use App\Models\EstoqueMovimentacao;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Serviço responsável pelas consultas e regras de movimentações de estoque.
 */
class EstoqueMovimentacaoService
{
    /**
     * Busca movimentações de estoque com base nos filtros fornecidos.
     *
     * @param FiltroMovimentacaoEstoqueDTO $filtros DTO com os critérios de filtro
     * @return LengthAwarePaginator Lista paginada de movimentações
     */
    public function buscarComFiltros(FiltroMovimentacaoEstoqueDTO $filtros): LengthAwarePaginator
    {
        $query = EstoqueMovimentacao::with([
            'variacao.produto',
            'variacao.atributos',
            'usuario',
            'depositoOrigem',
            'depositoDestino'
        ]);

        if ($filtros->variacao) {
            $query->where('id_variacao', $filtros->variacao);
        }

        if ($filtros->tipo) {
            $query->where('tipo', $filtros->tipo);
        }

        if ($filtros->produto) {
            $query->where(function ($q) use ($filtros) {
                $q->whereHas('variacao.produto', function ($sub) use ($filtros) {
                    $sub->where('nome', 'like', "%{$filtros->produto}%");
                })->orWhereHas('variacao', function ($sub) use ($filtros) {
                    $sub->where('referencia', 'like', "%{$filtros->produto}%");
                });
            });
        }

        if ($filtros->deposito) {
            $query->where(function ($q) use ($filtros) {
                $q->where('id_deposito_origem', $filtros->deposito)
                    ->orWhere('id_deposito_destino', $filtros->deposito);
            });
        }

        if ($filtros->periodo && count($filtros->periodo) === 2) {
            $query->whereBetween('data_movimentacao', $filtros->periodo);
        }

        $sortField = match ($filtros->sortField) {
            'produto_nome' => DB::raw('(select nome from produtos where produtos.id = (select produto_id from produto_variacoes where produto_variacoes.id = estoque_movimentacoes.id_variacao))'),
            'tipo' => 'tipo',
            'quantidade' => 'quantidade',
            default => 'data_movimentacao',
        };

        return $query->orderBy($sortField, $filtros->sortOrder)
            ->paginate($filtros->perPage);
    }
}
