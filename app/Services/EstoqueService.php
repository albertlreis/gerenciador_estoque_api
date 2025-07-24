<?php

namespace App\Services;

use App\DTOs\FiltroEstoqueDTO;
use App\Models\ProdutoVariacao;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Serviço responsável pela lógica de consulta e resumo de estoque.
 */
class EstoqueService
{
    /**
     * Consulta o estoque agrupado por produto e depósito, com filtros e ordenação.
     *
     * @param FiltroEstoqueDTO $filtros DTO contendo os filtros da consulta
     * @return LengthAwarePaginator Lista paginada de produtos com estoque
     */
    public function obterEstoqueAgrupadoPorProdutoEDeposito(FiltroEstoqueDTO $filtros): LengthAwarePaginator
    {
        $query = ProdutoVariacao::with([
            'produto',
            'atributos',
            'estoquesComLocalizacao' => function ($q) use ($filtros) {
                if ($filtros->deposito) {
                    $q->where('id_deposito', $filtros->deposito);
                }
            }
        ])
            ->withSum(['estoque as quantidade_estoque' => function ($q) use ($filtros) {
                if ($filtros->deposito) {
                    $q->where('id_deposito', $filtros->deposito);
                }
                if ($filtros->periodo && count($filtros->periodo) === 2) {
                    $q->whereBetween('updated_at', $filtros->periodo);
                }
            }], 'quantidade');

        if ($filtros->produto) {
            $query->where(function ($q) use ($filtros) {
                $q->whereHas('produto', function ($sub) use ($filtros) {
                    $sub->where('nome', 'like', "%{$filtros->produto}%");
                })->orWhere('referencia', 'like', "%{$filtros->produto}%");
            });
        }

        if ($filtros->deposito) {
            $query->whereHas('estoquesComLocalizacao', function ($q) use ($filtros) {
                $q->where('id_deposito', $filtros->deposito);
            });
        }

        if ($filtros->zerados) {
            $query->havingRaw('quantidade_estoque = 0 OR quantidade_estoque IS NULL');
        }

        $sortableMap = [
            'produto_nome' => DB::raw('(select nome from produtos where produtos.id = produto_variacoes.produto_id)'),
            'referencia' => 'referencia',
            'quantidade' => 'quantidade_estoque',
            'deposito_nome' => DB::raw('(select nome from depositos
                where depositos.id = (
                    select estoque.id_deposito
                    from estoque
                    where estoque.id_variacao = produto_variacoes.id
                    order by estoque.id asc
                    limit 1
                )
            )'),
        ];

        if ($filtros->sortField && isset($sortableMap[$filtros->sortField])) {
            $query->orderBy($sortableMap[$filtros->sortField], $filtros->sortOrder ?? 'asc');
        }

        return $query->paginate($filtros->perPage);
    }

    /**
     * Gera um resumo do estoque com totais de produtos, peças e depósitos.
     *
     * @param string|null $produto Nome ou referência do produto
     * @param int|null $deposito ID do depósito
     * @param array<int, string>|null $periodo Período de atualização do estoque
     * @return array<string, int> Totais agregados
     */
    public function gerarResumoEstoque(
        ?string $produto = null,
        ?int $deposito = null,
        ?array $periodo = null
    ): array {
        $estoqueQuery = DB::table('estoque')
            ->join('produto_variacoes', 'estoque.id_variacao', '=', 'produto_variacoes.id')
            ->join('produtos', 'produto_variacoes.produto_id', '=', 'produtos.id');

        if ($produto) {
            $estoqueQuery->where(function ($q) use ($produto) {
                $q->where('produtos.nome', 'like', "%$produto%")
                    ->orWhere('produto_variacoes.referencia', 'like', "%$produto%");
            });
        }

        if ($deposito) {
            $estoqueQuery->where('estoque.id_deposito', $deposito);
        }

        if ($periodo && count($periodo) === 2) {
            $estoqueQuery->whereBetween('estoque.updated_at', [$periodo[0], $periodo[1]]);
        }

        return [
            'totalProdutos' => DB::table('produtos')->count(),
            'totalPecas' => $estoqueQuery->sum('estoque.quantidade'),
            'totalDepositos' => DB::table('depositos')->count(),
        ];
    }
}
