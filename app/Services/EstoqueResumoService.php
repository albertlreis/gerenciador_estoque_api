<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Serviço responsável por gerar métricas/resumo de estoque.
 */
class EstoqueResumoService
{
    /**
     * Gera um resumo do estoque a partir de filtros simples.
     *
     * Observações de desempenho:
     * - totalProdutos e totalDepositos são contagens simples (podem ser cacheadas se necessário).
     * - totalPecas reaproveita a query base de estoque com joins.
     *
     * @param string|null $produto   Texto para busca em produtos.nome ou produto_variacoes.referencia
     * @param int|null    $deposito  ID do depósito
     * @param array|null  $periodo   Intervalo [inicio, fim] (string/datetime aceito pelo banco)
     *
     * @return array{totalProdutos:int,totalPecas:int,totalDepositos:int}
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
            $term = trim($produto);

            $estoqueQuery->where(function ($q) use ($term) {
                $q->where('produtos.nome', 'like', "%{$term}%")
                    ->orWhere('produto_variacoes.referencia', 'like', "%{$term}%");
            });
        }

        if ($deposito) {
            $estoqueQuery->where('estoque.id_deposito', $deposito);
        }

        if ($periodo && count($periodo) === 2 && $periodo[0] && $periodo[1]) {
            $estoqueQuery->whereBetween('estoque.updated_at', [$periodo[0], $periodo[1]]);
        }

        // Clona por segurança (evita efeitos colaterais se você estender essa query no futuro)
        $sumQuery = clone $estoqueQuery;

        return [
            'totalProdutos'  => DB::table('produtos')->count(),
            'totalPecas'     => (int) $sumQuery->sum('estoque.quantidade'),
            'totalDepositos' => DB::table('depositos')->count(),
        ];
    }
}
