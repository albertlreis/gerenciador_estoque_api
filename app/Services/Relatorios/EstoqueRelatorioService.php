<?php

namespace App\Services\Relatorios;

use Illuminate\Support\Facades\DB;

class EstoqueRelatorioService
{
    /**
     * Retorna o estoque atual por produto e depósito.
     *
     * @param array $filtros Filtros opcionais: deposito_id, categoria_id
     * @return array
     */
    public function obterEstoqueAtual(array $filtros): array
    {
        $query = DB::table('produto_variacoes as pv')
            ->join('produtos as p', 'p.id', '=', 'pv.produto_id')
            ->join('estoque as e', 'e.id_variacao', '=', 'pv.id')
            ->select(
                'p.nome as produto',
                'pv.id as variacao_id',
                DB::raw('SUM(e.quantidade) as estoque_total'),
                'e.id_deposito'
            )
            ->groupBy('p.nome', 'pv.id', 'e.id_deposito');

        if (!empty($filtros['deposito_id'])) {
            $query->where('e.id_deposito', $filtros['deposito_id']);
        }

        if (!empty($filtros['categoria_id'])) {
            $query->where('p.categoria_id', $filtros['categoria_id']);
        }

        $estoques = $query->get();

        return $estoques->groupBy('produto')->map(function ($group) {
            return [
                'estoque_total' => $group->sum('estoque_total'),
                'estoque_por_deposito' => $group->mapWithKeys(function ($item) {
                    return [$item->id_deposito => (int) $item->estoque_total];
                }),
            ];
        })->toArray();
    }
}
