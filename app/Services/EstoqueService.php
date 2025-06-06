<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class EstoqueService
{
    public function obterEstoqueAgrupadoPorProdutoEDeposito(): array
    {
        return DB::table('estoque')
            ->join('produto_variacoes', 'estoque.id_variacao', '=', 'produto_variacoes.id')
            ->join('produtos', 'produto_variacoes.produto_id', '=', 'produtos.id')
            ->join('depositos', 'estoque.id_deposito', '=', 'depositos.id')
            ->select(
                'produtos.id as produto_id',
                'produtos.nome as produto_nome',
                'depositos.nome as deposito_nome',
                DB::raw('SUM(estoque.quantidade) as quantidade')
            )
            ->groupBy('produtos.id', 'produtos.nome', 'depositos.nome')
            ->orderBy('produtos.nome')
            ->get()
            ->toArray();
    }

    public function gerarResumoEstoque(): array
    {
        return [
            'totalProdutos' => DB::table('produtos')->count(),
            'totalPecas' => DB::table('estoque')->sum('quantidade'),
            'totalDepositos' => DB::table('depositos')->count(),
        ];
    }

}
