<?php

namespace App\Services\Relatorios;

use Illuminate\Support\Facades\DB;

/**
 * Serviço de dados para Relatório de Estoque Atual.
 *
 * - Quando $filtros['somente_outlet'] = true:
 *   * Totalizadores passam a refletir APENAS outlet (pvo.quantidade_restante).
 * - Filtros: deposito_ids[], deposito_id, categoria_id, produto_id, somente_outlet.
 */
class EstoqueRelatorioService
{
    /**
     * Monta o dataset do relatório de estoque atual.
     *
     * @param array{
     *   deposito_ids?:array<int>,
     *   deposito_id?:int,
     *   categoria_id?:int,
     *   produto_id?:int,
     *   somente_outlet?:bool
     * } $filtros
     * @return array<string, array{
     *   estoque_total:int,
     *   valor_total:float,
     *   estoque_por_deposito: array<int, array{id:int, nome:string, quantidade:int, valor:float}>,
     *   variacoes: array<int, array{variacao_id:int, referencia:string, valor_item:float, estoque_total:int, valor_total:float}>,
     *   categoria?: string,
     *   imagem_principal?: string
     * }>
     */
    public function obterEstoqueAtual(array $filtros): array
    {
        $somenteOutlet = !empty($filtros['somente_outlet']);

        // 1) Prefiltro de variações elegíveis
        $pvElegiveis = DB::table('produto_variacoes as pv')
            ->join('produtos as p', 'p.id', '=', 'pv.produto_id')
            ->when(!empty($filtros['categoria_id']), fn ($q) =>
            $q->where('p.id_categoria', (int) $filtros['categoria_id'])
            )
            ->when(!empty($filtros['produto_id']), fn ($q) =>
            $q->where('p.id', (int) $filtros['produto_id'])
            );

        if ($somenteOutlet) {
            $pvElegiveis->join('produto_variacao_outlets as pvo', function ($j) {
                $j->on('pvo.produto_variacao_id', '=', 'pv.id')
                    ->where('pvo.quantidade_restante', '>', 0);
            });
        }

        $pvElegiveis = $pvElegiveis
            ->leftJoin('categorias as c', 'c.id', '=', 'p.id_categoria')
            ->leftJoin('produto_imagens as pi', function ($j) {
                $j->on('pi.id_produto', '=', 'p.id')->where('pi.principal', '=', 1);
            })
            ->select([
                'pv.id as variacao_id',
                'pv.referencia',
                'pv.preco as valor_item',
                'p.id as produto_id',
                'p.nome as produto',
                'c.nome as categoria_nome',
                'pi.url as imagem_principal',
            ]);

        $elig = DB::query()->fromSub($pvElegiveis, 'elig');

        // 2) Estoque físico por depósito (usa índices em estoque)
        $main = DB::table('estoque as e')
            ->joinSub($elig, 'elig', 'elig.variacao_id', '=', 'e.id_variacao')
            ->join('depositos as d', 'd.id', '=', 'e.id_deposito')
            ->when(!empty($filtros['deposito_ids']) && is_array($filtros['deposito_ids']), fn ($q) =>
            $q->whereIn('e.id_deposito', array_map('intval', $filtros['deposito_ids']))
            )
            ->when(!empty($filtros['deposito_id']), fn ($q) =>
            $q->where('e.id_deposito', (int) $filtros['deposito_id'])
            )
            ->select([
                'elig.produto_id',
                'elig.produto',
                'elig.categoria_nome',
                'elig.variacao_id',
                'elig.referencia',
                'elig.valor_item',
                'elig.imagem_principal',
                'e.id_deposito',
                'd.nome as deposito_nome',
                DB::raw('SUM(e.quantidade) as estoque_total_fisico'),
                DB::raw('SUM(e.quantidade * elig.valor_item) as valor_total_fisico'),
            ])
            ->groupBy(
                'elig.produto_id','elig.produto','elig.categoria_nome',
                'elig.variacao_id','elig.referencia','elig.valor_item',
                'elig.imagem_principal',
                'e.id_deposito','d.nome'
            );

        $linhas = $main->get();

        // 3) Totais outlet com pré-agregação
        $totaisOutlet = [];
        if ($somenteOutlet) {
            $pvoAgg = DB::table('produto_variacao_outlets')
                ->selectRaw('produto_variacao_id, SUM(quantidade_restante) AS qt_outlet')
                ->where('quantidade_restante', '>', 0)
                ->groupBy('produto_variacao_id');

            $pvoAggSql = DB::query()->fromSub($pvoAgg, 'pvo');

            $aggOutlet = DB::query()
                ->fromSub($pvElegiveis, 'elig')
                ->joinSub($pvoAggSql, 'pvo', 'pvo.produto_variacao_id', '=', 'elig.variacao_id')
                ->select([
                    'elig.produto_id',
                    DB::raw('SUM(pvo.qt_outlet) AS qt_outlet'),
                    DB::raw('SUM(pvo.qt_outlet * elig.valor_item) AS vl_outlet'),
                ])
                ->groupBy('elig.produto_id')
                ->get()
                ->keyBy('produto_id');

            $totaisOutlet = $aggOutlet;
        }

        // 4) Montagem final (igual ao seu, sem mudanças)
        $resultado = collect($linhas)->groupBy('produto_id')->map(function ($group) use ($somenteOutlet, $totaisOutlet) {
            $first = $group->first();

            $porDeposito = [];
            foreach ($group as $item) {
                $depId = (int) $item->id_deposito;
                if (!isset($porDeposito[$depId])) {
                    $porDeposito[$depId] = [
                        'id'         => $depId,
                        'nome'       => $item->deposito_nome,
                        'quantidade' => 0,
                        'valor'      => 0.0,
                    ];
                }
                $porDeposito[$depId]['quantidade'] += (int) $item->estoque_total_fisico;
                $porDeposito[$depId]['valor']      += (float) $item->valor_total_fisico;
            }

            $variacoes = collect($group)->groupBy('variacao_id')->map(function ($g) {
                $f = $g->first();
                return [
                    'variacao_id'   => (int) $f->variacao_id,
                    'referencia'    => (string) $f->referencia,
                    'valor_item'    => (float) $f->valor_item,
                    'estoque_total' => (int) $g->sum('estoque_total_fisico'),
                    'valor_total'   => (float) $g->sum('valor_total_fisico'),
                ];
            })->values();

            if ($somenteOutlet) {
                $sum = $totaisOutlet[$first->produto_id] ?? null;
                $estoqueTotal = (int) ($sum->qt_outlet ?? 0);
                $valorTotal   = (float) ($sum->vl_outlet ?? 0.0);
            } else {
                $estoqueTotal = (int) collect($group)->sum('estoque_total_fisico');
                $valorTotal   = (float) collect($group)->sum('valor_total_fisico');
            }

            return [
                'estoque_total'        => $estoqueTotal,
                'valor_total'          => $valorTotal,
                'estoque_por_deposito' => array_values($porDeposito),
                'variacoes'            => $variacoes,
                'categoria'            => $first->categoria_nome ?? null,
                'imagem_principal'     => request()->query('formato') === 'excel' ? null : ($first->imagem_principal ?? null),
            ];
        })->toArray();

        return $resultado;
    }
}
