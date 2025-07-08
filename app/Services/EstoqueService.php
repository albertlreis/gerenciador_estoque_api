<?php

namespace App\Services;

use App\Models\ProdutoVariacao;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EstoqueService
{
    public function obterEstoqueAgrupadoPorProdutoEDeposito(
        ?string $produto = null,
        ?int $deposito = null,
        ?array $periodo = null,
        int $perPage = 10
    ): LengthAwarePaginator {
        $query = ProdutoVariacao::with(['produto', 'atributos', 'estoque', 'estoque.deposito'])
            ->withSum(['estoque as quantidade_estoque' => function ($q) use ($deposito, $periodo) {
                if ($deposito) {
                    $q->where('id_deposito', $deposito);
                }
                if ($periodo && count($periodo) === 2) {
                    $q->whereBetween('updated_at', [$periodo[0], $periodo[1]]);
                }
            }], 'quantidade');

        if ($produto) {
            $query->whereHas('produto', function ($q) use ($produto) {
                $q->where('nome', 'like', "%{$produto}%")
                    ->orWhere('referencia', 'like', "%{$produto}%");
            });
        }

        return $query->paginate($perPage);
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
