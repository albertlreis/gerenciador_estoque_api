<?php

namespace App\Services\Relatorios;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Serviço de dados para Relatório de Estoque Atual.
 *
 * Filtros aceitos:
 * - deposito_ids?: int[]
 * - deposito_id?: int
 * - categoria_id?: int
 * - produto_id?: int
 * - somente_outlet?: bool
 *
 * Retorno (por produto_id):
 * [
 *   produto_id => [
 *     estoque_total: int,
 *     valor_total: float,
 *     estoque_por_deposito: [ {id, nome, quantidade, valor}, ... ],
 *     variacoes: [ {variacao_id, sku_interno, referencia, identificador_variacao, valor_item, estoque_total, valor_total}, ... ],
 *     categoria?: string,
 *     imagem_principal?: string|null,
 *     produto: string,
 *     produto_id: int
 *   ],
 *   ...
 * ]
 */
class EstoqueRelatorioService
{
    /** @param array<string,mixed> $filtros */
    public function obterEstoqueAtual(array $filtros): array
    {
        $somenteOutlet = $this->isSomenteOutlet($filtros);

        // 1) Elegíveis (subquery com filtros básicos + joins “leves”)
        $pvElegiveis = $this->buildElegiveisQuery($filtros, $somenteOutlet);
        $elig        = $this->toSub($pvElegiveis, 'elig');

        // 2) Query principal: estoque físico por depósito (já agregada no SQL)
        $mainQuery = $this->buildMainStockQuery($elig, $filtros);

        // 3) Execução (coleção com linhas agregadas por variação x depósito)
        $linhas = $this->fetchMainRows($mainQuery);

        // 4) Totais de outlet (somente quando solicitado)
        if ($somenteOutlet) {
            [$totaisOutlet, $totaisOutletPorVariacao] = $this->computeOutletTotalsMaps($pvElegiveis);
        } else {
            $totaisOutlet = collect();
            $totaisOutletPorVariacao = collect();
        }

        // 5) Agregação final (por produto)
        return $this->aggregateByProduto($linhas, $somenteOutlet, $totaisOutlet, $totaisOutletPorVariacao);
    }

    /* =========================
       Helpers: Filtros / Flags
       ========================= */

    /** @param array<string,mixed> $filtros */
    protected function isSomenteOutlet(array $filtros): bool
    {
        return !empty($filtros['somente_outlet']);
    }

    /* ==============================
       Passo 1: Elegíveis (subquery)
       ============================== */

    /**
     * Monta a query base de variações elegíveis, com filtros e joins de produto/categoria/imagem.
     * NÃO executa a query; retorna um Builder.
     *
     * @param array<string,mixed> $filtros
     */
    protected function buildElegiveisQuery(array $filtros, bool $somenteOutlet): Builder
    {
        $q = DB::table('produto_variacoes as pv')
            ->join('produtos as p', 'p.id', '=', 'pv.produto_id')
            ->when(!empty($filtros['categoria_id']), fn(Builder $qb) =>
            $qb->where('p.id_categoria', (int) $filtros['categoria_id'])
            )
            ->when(!empty($filtros['produto_id']), fn(Builder $qb) =>
            $qb->where('p.id', (int) $filtros['produto_id'])
            );

        if ($somenteOutlet) {
            // Garante que só entram variações com outlet restante > 0
            $q->join('produto_variacao_outlets as pvo', function ($j) {
                $j->on('pvo.produto_variacao_id', '=', 'pv.id')
                    ->where('pvo.quantidade_restante', '>', 0);
            });
        }

        return $q
            ->leftJoin('categorias as c', 'c.id', '=', 'p.id_categoria')
            ->leftJoin('produto_imagens as pi', function ($j) {
                $j->on('pi.id_produto', '=', 'p.id')->where('pi.principal', '=', 1);
            })
            ->select([
                'pv.id as variacao_id',
                'pv.sku_interno',
                'pv.referencia',
                'pv.preco as valor_item',
                'p.id as produto_id',
                'p.nome as produto',
                'p.codigo_produto',
                'c.nome as categoria_nome',
                'pi.url as imagem_principal',
            ]);
    }

    protected function toSub(Builder $query, string $alias): Builder
    {
        // DB::query()->fromSub(...) preserva a query como uma subquery reutilizável
        return DB::query()->fromSub($query, $alias);
    }

    /* ==============================
       Passo 2: Query principal (SQL)
       ============================== */

    /**
     * Monta a query que agrega estoque físico por (variação, depósito).
     * @param Builder $elig Subquery “elig” (retornada por toSub()).
     * @param array<string,mixed> $filtros
     */
    protected function buildMainStockQuery(Builder $elig, array $filtros): Builder
    {
        return DB::table('estoque as e')
            ->joinSub($elig, 'elig', 'elig.variacao_id', '=', 'e.id_variacao')
            ->join('depositos as d', 'd.id', '=', 'e.id_deposito')
            ->where('e.quantidade', '>', 0) // ⬅️ corte fundamental
            ->when(!empty($filtros['deposito_ids']) && is_array($filtros['deposito_ids']), fn(Builder $qb) =>
            $qb->whereIn('e.id_deposito', array_map('intval', $filtros['deposito_ids']))
            )
            ->when(!empty($filtros['deposito_id']), fn(Builder $qb) =>
            $qb->where('e.id_deposito', (int) $filtros['deposito_id'])
            )
            ->select([
                'elig.produto_id','elig.produto','elig.categoria_nome',
                'elig.codigo_produto','elig.variacao_id','elig.sku_interno','elig.referencia','elig.valor_item','elig.imagem_principal',
                'e.id_deposito','d.nome as deposito_nome',
                DB::raw('SUM(e.quantidade) as estoque_total_fisico'),
                DB::raw('SUM(e.quantidade * elig.valor_item) as valor_total_fisico'),
            ])
            ->groupBy(
                'elig.produto_id','elig.produto','elig.categoria_nome',
                'elig.codigo_produto','elig.variacao_id','elig.sku_interno','elig.referencia','elig.valor_item','elig.imagem_principal',
                'e.id_deposito','d.nome'
            );
    }

    /* ==============================
       Passo 3: Execução da principal
       ============================== */

    /** Executa a query principal. Mantém a compatibilidade com o fluxo atual. */
    protected function fetchMainRows(Builder $mainQuery): Collection
    {
        // Se o volume crescer muito, podemos trocar para ->orderBy(...)->cursor(),
        // e agregar “on the fly” para reduzir memória. Ver “aggregateStreaming()” abaixo.
        return $mainQuery->get();
    }

    /* ========================================
       Passo 4: Totais de outlet (pré-agregação)
       ======================================== */

    /**
     * Calcula totais de outlet (quantidade_restante e valor) por produto_id.
     * Reusa a subquery de elegíveis para garantir consistência de filtros.
     *
     * @return Collection keyed by produto_id with (qt_outlet, vl_outlet)
     */
    protected function computeOutletTotals(Builder $pvElegiveis): Collection
    {
        $pvoAgg = DB::table('produto_variacao_outlets')
            ->selectRaw('produto_variacao_id, SUM(quantidade_restante) AS qt_outlet')
            ->where('quantidade_restante', '>', 0)
            ->groupBy('produto_variacao_id');

        $pvoAggSql = $this->toSub($pvoAgg, 'pvo');

        return DB::query()
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
    }

    /* ==============================
       Passo 5: Agregação final
       ============================== */

    /**
     * @param Collection<int,object> $linhas  // rows variação x depósito
     * @param Collection<int,object> $totaisOutlet           // by produto_id
     * @param Collection<int,object> $totaisOutletPorVariacao// by variacao_id
     */
    protected function aggregateByProduto(
        Collection $linhas,
        bool $somenteOutlet,
        Collection $totaisOutlet,
        Collection $totaisOutletPorVariacao
    ): array {
        return $linhas->groupBy('produto_id')->map(function (Collection $group) use ($somenteOutlet, $totaisOutlet, $totaisOutletPorVariacao) {
            /** @var object $first */
            $first = $group->first();

            // a) Totais por depósito
            $porDeposito = [];

            if ($somenteOutlet) {
                // Distribui outlet da variação proporcionalmente ao estoque físico por depósito
                $byVar = $group->groupBy('variacao_id');
                foreach ($byVar as $variacaoId => $g) {
                    $f = $g->first();
                    $qtOutletVar = (int) ($totaisOutletPorVariacao->get($variacaoId)->qt_outlet ?? 0);
                    if ($qtOutletVar <= 0) continue;

                    $totalFisicoVar = (int) $g->sum('estoque_total_fisico');
                    if ($totalFisicoVar <= 0) continue;

                    foreach ($g as $row) {
                        $depId = (int) $row->id_deposito;
                        if (!isset($porDeposito[$depId])) {
                            $porDeposito[$depId] = ['id' => $depId, 'nome' => (string)$row->deposito_nome, 'quantidade' => 0, 'valor' => 0.0];
                        }

                        $share = ($row->estoque_total_fisico / $totalFisicoVar) * $qtOutletVar; // float
                        $porDeposito[$depId]['quantidade'] += $share;
                        $porDeposito[$depId]['valor']      += $share * (float)$f->valor_item;
                    }
                }

                // Arredondamentos finais (mantém números “limpos” no PDF)
                foreach ($porDeposito as &$d) {
                    $d['quantidade'] = (int) round($d['quantidade']);         // inteiro
                    $d['valor']      = (float) round($d['valor'], 2);          // 2 casas p/ dinheiro
                }
                unset($d);
            } else {
                // Modo normal: usa o físico diretamente
                foreach ($group as $item) {
                    $depId = (int) $item->id_deposito;
                    if (!isset($porDeposito[$depId])) {
                        $porDeposito[$depId] = ['id' => $depId, 'nome' => (string)$item->deposito_nome, 'quantidade' => 0, 'valor' => 0.0];
                    }
                    $porDeposito[$depId]['quantidade'] += (int) $item->estoque_total_fisico;
                    $porDeposito[$depId]['valor']      += (float) $item->valor_total_fisico;
                }
            }

            // b) Totais por variação (para a coluna “variações” do payload)
            $variacoes = $group->groupBy('variacao_id')->map(function (Collection $g) {
                $f = $g->first();
                return [
                    'variacao_id'   => (int) $f->variacao_id,
                    'sku_interno'   => $f->sku_interno ? (string) $f->sku_interno : null,
                    'referencia'    => (string) $f->referencia,
                    'identificador_variacao' => $f->sku_interno ?: $f->referencia,
                    'valor_item'    => (float) $f->valor_item,
                    'estoque_total' => (int) $g->sum('estoque_total_fisico'),
                    'valor_total'   => (float) $g->sum('valor_total_fisico'),
                ];
            })->values();

            // c) Totais gerais (outlet ou físico)
            if ($somenteOutlet) {
                $sum          = $totaisOutlet->get($first->produto_id);
                $estoqueTotal = (int) ($sum->qt_outlet ?? 0);
                $valorTotal   = (float) ($sum->vl_outlet ?? 0.0);
            } else {
                $estoqueTotal = (int) $group->sum('estoque_total_fisico');
                $valorTotal   = (float) $group->sum('valor_total_fisico');
            }

            return [
                'estoque_total'        => $estoqueTotal,
                'valor_total'          => $valorTotal,
                'estoque_por_deposito' => array_values($porDeposito), // 🔁 agora outlet-aware quando preciso
                'variacoes'            => $variacoes,
                'categoria'            => $first->categoria_nome ?? null,
                'imagem_principal'     => request()->query('formato') === 'excel' ? null : ($first->imagem_principal ?? null),
                'produto'              => (string) ($first->produto ?? ''),
                'produto_id'           => (int) ($first->produto_id ?? 0),
                'codigo_produto'       => $first->codigo_produto ?? null,
            ];
        })->toArray();
    }

    /** @return array<int,array{id:int,nome:string,quantidade:int,valor:float}> */
    protected function buildDepositoTotals(Collection $group): array
    {
        $porDeposito = [];

        foreach ($group as $item) {
            $depId = (int) $item->id_deposito;

            if (!isset($porDeposito[$depId])) {
                $porDeposito[$depId] = [
                    'id'         => $depId,
                    'nome'       => (string) $item->deposito_nome,
                    'quantidade' => 0,
                    'valor'      => 0.0,
                ];
            }

            $porDeposito[$depId]['quantidade'] += (int) $item->estoque_total_fisico;
            $porDeposito[$depId]['valor']      += (float) $item->valor_total_fisico;
        }

        return $porDeposito;
    }

    /** @return array<int,array{variacao_id:int,sku_interno:?string,referencia:string,identificador_variacao:?string,valor_item:float,estoque_total:int,valor_total:float}> */
    protected function buildVariacaoTotals(Collection $group): array
    {
        return $group->groupBy('variacao_id')->map(function (Collection $g) {
            $f = $g->first();
            return [
                'variacao_id'   => (int) $f->variacao_id,
                'sku_interno'   => $f->sku_interno ? (string) $f->sku_interno : null,
                'referencia'    => (string) $f->referencia,
                'identificador_variacao' => $f->sku_interno ?: $f->referencia,
                'valor_item'    => (float) $f->valor_item,
                'estoque_total' => (int) $g->sum('estoque_total_fisico'),
                'valor_total'   => (float) $g->sum('valor_total_fisico'),
            ];
        })->values()->all();
    }

    /* ===========================================================
       Alternativa “heavy data”: agregação por streaming (opcional)
       ===========================================================
       Caso o dataset fique muito grande para caber em memória,
       troque fetchMainRows() para um cursor ordenado por produto_id
       e use este método para agregar “on the fly”.
       Mantém o mesmo formato de saída.
    */

    /**
     * Exemplo de agregação em streaming (não usada por padrão).
     * @param Builder $mainQuery
     * @param bool $somenteOutlet
     * @param Collection $totaisOutlet keyBy produto_id
     * @return array<string,array<string,mixed>>
     */
    protected function aggregateStreaming(Builder $mainQuery, bool $somenteOutlet, Collection $totaisOutlet): array
    {
        $result = [];
        $currentPid = null;
        $currentGroup = collect();

        // Garantir ordenação por produto para stream estável
        $cursor = $mainQuery
            ->orderBy('elig.produto_id')
            ->orderBy('elig.variacao_id')
            ->orderBy('e.id_deposito')
            ->cursor();

        foreach ($cursor as $row) {
            if ($currentPid !== null && $row->produto_id !== $currentPid) {
                // Fecha o grupo anterior
                $result[$currentPid] = $this->aggregateByProduto($currentGroup, $somenteOutlet, $totaisOutlet)[$currentPid] ?? [];
                $currentGroup = collect();
            }
            $currentPid = $row->produto_id;
            $currentGroup->push($row);
        }

        // Último grupo
        if ($currentPid !== null && $currentGroup->isNotEmpty()) {
            $result[$currentPid] = $this->aggregateByProduto($currentGroup, $somenteOutlet, $totaisOutlet)[$currentPid] ?? [];
        }

        return $result;
    }

    // app/Services/Relatorios/EstoqueRelatorioService.php

    /** @return array{Collection, Collection} [porProduto, porVariacao] */
    protected function computeOutletTotalsMaps(Builder $pvElegiveis): array
    {
        $pvoAgg = DB::table('produto_variacao_outlets')
            ->selectRaw('produto_variacao_id, SUM(quantidade_restante) AS qt_outlet')
            ->where('quantidade_restante', '>', 0)
            ->groupBy('produto_variacao_id');

        $pvoAggSql = $this->toSub($pvoAgg, 'pvo');

        // Totais por PRODUTO (já existia)
        $porProduto = DB::query()
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

        // Totais por VARIAÇÃO (novo)
        $porVariacao = DB::query()
            ->fromSub($pvElegiveis, 'elig')
            ->joinSub($pvoAggSql, 'pvo', 'pvo.produto_variacao_id', '=', 'elig.variacao_id')
            ->select([
                'elig.variacao_id',
                DB::raw('SUM(pvo.qt_outlet) AS qt_outlet'),
            ])
            ->groupBy('elig.variacao_id')
            ->get()
            ->keyBy('variacao_id');

        return [$porProduto, $porVariacao];
    }
}
