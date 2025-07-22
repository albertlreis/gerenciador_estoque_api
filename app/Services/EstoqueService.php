<?php

namespace App\Services;

use App\Models\ProdutoVariacao;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EstoqueService
{
    /**
     * Consulta de estoque agrupado por produto e depósito, com filtros e paginação.
     *
     * @param string|null $produto
     * @param int|null $deposito
     * @param array|null $periodo
     * @param int $perPage
     * @param bool $zerados
     * @return LengthAwarePaginator
     */
    public function obterEstoqueAgrupadoPorProdutoEDeposito(
        ?string $produto = null,
        ?int $deposito = null,
        ?array $periodo = null,
        int $perPage = 10,
        bool $zerados = false
    ): LengthAwarePaginator {
        $query = ProdutoVariacao::with([
            'produto',
            'atributos',
            'estoquesComLocalizacao' => function ($q) use ($deposito) {
                if ($deposito) {
                    $q->where('id_deposito', $deposito);
                }
            }
        ])
            ->withSum(['estoque as quantidade_estoque' => function ($q) use ($deposito, $periodo) {
                if ($deposito) {
                    $q->where('id_deposito', $deposito);
                }
                if ($periodo && count($periodo) === 2) {
                    $q->whereBetween('updated_at', [$periodo[0], $periodo[1]]);
                }
            }], 'quantidade');

        if ($produto) {
            $query->where(function ($q) use ($produto) {
                $q->whereHas('produto', function ($sub) use ($produto) {
                    $sub->where('nome', 'like', "%$produto%");
                })->orWhere('referencia', 'like', "%$produto%");
            });
        }

        if ($deposito) {
            $query->whereHas('estoquesComLocalizacao', function ($q) use ($deposito) {
                $q->where('id_deposito', $deposito);
            });
        }

        if ($zerados) {
            $query->havingRaw('quantidade_estoque = 0 OR quantidade_estoque IS NULL');
        }

        return $query->paginate($perPage);
    }

    /**
     * Gera o resumo de estoque com totais.
     *
     * @param string|null $produto
     * @param int|null $deposito
     * @param array|null $periodo
     * @return array<string, int>
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
