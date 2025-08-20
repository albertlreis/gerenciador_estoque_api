<?php

namespace App\Services\Relatorios;

use Illuminate\Support\Facades\DB;

class EstoqueRelatorioService
{
    /**
     * Retorna o estoque atual por produto/variação e por depósito, incluindo valor total (sempre pv.preco).
     *
     * Filtros aceitos:
     * - deposito_ids[] (array) ou deposito_id (legado)
     * - categoria_id
     * - somente_outlet (bool)
     *
     * @param array $filtros
     * @return array
     */
    public function obterEstoqueAtual(array $filtros): array
    {
        $query = DB::table('produto_variacoes as pv')
            ->join('produtos as p', 'p.id', '=', 'pv.produto_id')
            ->join('estoque as e', 'e.id_variacao', '=', 'pv.id')
            ->join('depositos as d', 'd.id', '=', 'e.id_deposito') // <-- nome do depósito
            ->select(
                'p.id as produto_id',
                'p.nome as produto',
                'pv.id as variacao_id',
                'pv.referencia',
                DB::raw('pv.preco as valor_item'),
                DB::raw('SUM(e.quantidade) as estoque_total'),
                DB::raw('SUM(e.quantidade * pv.preco) as valor_total'),
                'e.id_deposito',
                'd.nome as deposito_nome' // <-- seleciona o nome
            )
            ->groupBy(
                'p.id', 'p.nome',
                'pv.id', 'pv.referencia', 'pv.preco',
                'e.id_deposito',
                'd.nome' // <-- agrupa pelo nome
            );

        // Filtros de depósito
        if (!empty($filtros['deposito_ids']) && is_array($filtros['deposito_ids'])) {
            $query->whereIn('e.id_deposito', $filtros['deposito_ids']);
        } elseif (!empty($filtros['deposito_id'])) {
            $query->where('e.id_deposito', $filtros['deposito_id']);
        }

        if (!empty($filtros['categoria_id'])) {
            $query->where('p.categoria_id', $filtros['categoria_id']);
        }

        if (!empty($filtros['somente_outlet'])) {
            $query->whereExists(function ($q) {
                $q->from('produto_variacao_outlets as pvo')
                    ->whereColumn('pvo.produto_variacao_id', 'pv.id')
                    ->where('pvo.quantidade_restante', '>', 0);
            });
        }

        $linhas = $query->get();

        $resultado = $linhas->groupBy('produto')->map(function ($group) {
            $estoqueTotal = (int) $group->sum('estoque_total');
            $valorTotal = (float) $group->sum('valor_total');

            // Monta estrutura por depósito com ID e NOME
            $porDepositoAssoc = [];
            foreach ($group as $item) {
                $depId = $item->id_deposito;
                if (!isset($porDepositoAssoc[$depId])) {
                    $porDepositoAssoc[$depId] = [
                        'id'         => $depId,
                        'nome'       => $item->deposito_nome,
                        'quantidade' => 0,
                        'valor'      => 0.0,
                    ];
                }
                $porDepositoAssoc[$depId]['quantidade'] += (int) $item->estoque_total;
                $porDepositoAssoc[$depId]['valor']      += (float) $item->valor_total;
            }

            // Detalhes de variações (útil para Excel)
            $variacoes = $group->groupBy('variacao_id')->map(function ($g) {
                return [
                    'variacao_id'    => $g->first()->variacao_id,
                    'referencia'     => $g->first()->referencia,
                    'valor_item'     => (float) $g->first()->valor_item,
                    'estoque_total'  => (int) $g->sum('estoque_total'),
                    'valor_total'    => (float) $g->sum('valor_total'),
                ];
            })->values();

            return [
                'estoque_total'        => $estoqueTotal,
                'valor_total'          => $valorTotal,
                'estoque_por_deposito' => array_values($porDepositoAssoc),
                'variacoes'            => $variacoes,
            ];
        })->toArray();

        return $resultado;
    }
}
