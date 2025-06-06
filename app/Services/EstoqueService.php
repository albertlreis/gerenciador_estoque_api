<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class EstoqueService
{
    public function obterEstoqueAgrupadoPorProdutoEDeposito(
        ?string $produto = null,
        ?int $deposito = null,
        ?array $periodo = null,
        int $perPage = 10
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
        $query = DB::table('estoque')
            ->join('produto_variacoes', 'estoque.id_variacao', '=', 'produto_variacoes.id')
            ->join('produtos', 'produto_variacoes.produto_id', '=', 'produtos.id')
            ->join('depositos', 'estoque.id_deposito', '=', 'depositos.id')
            ->select(
                'produtos.id as produto_id',
                'produtos.nome as produto_nome',
                'depositos.nome as deposito_nome',
                DB::raw('SUM(estoque.quantidade) as quantidade')
            );

        if ($produto) {
            $query->where(function ($q) use ($produto) {
                $q->where('produtos.nome', 'like', "%{$produto}%")
                    ->orWhere('produtos.referencia', 'like', "%{$produto}%");
            });
        }

        if ($deposito) {
            $query->where('estoque.id_deposito', $deposito);
        }

        if ($periodo && count($periodo) === 2) {
            $query->whereBetween('estoque.updated_at', [$periodo[0], $periodo[1]]);
        }

        return $query
            ->groupBy('produtos.id', 'produtos.nome', 'depositos.nome')
            ->orderBy('produtos.nome')
            ->paginate($perPage);
    }

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
                $q->where('produtos.nome', 'like', "%{$produto}%")
                    ->orWhere('produtos.referencia', 'like', "%{$produto}%");
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
