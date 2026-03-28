<?php

namespace App\Services\Dashboard\Queries;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class EstoqueDashboardQuery
{
    public function fetch(CarbonInterface $inicio, CarbonInterface $fim, ?int $depositoId = null): array
    {
        $baseMov = DB::table('estoque_movimentacoes')
            ->whereBetween('data_movimentacao', [$inicio->toDateTimeString(), $fim->toDateTimeString()]);

        if ($depositoId) {
            $baseMov->where(function (Builder $query) use ($depositoId) {
                $query->where('id_deposito_origem', $depositoId)
                    ->orWhere('id_deposito_destino', $depositoId);
            });
        }

        $tiposEntrada = config('dashboard.estoque.tipos_entrada', ['entrada']);
        $tiposSaida = config('dashboard.estoque.tipos_saida', ['saida']);
        $tiposTransferencia = config('dashboard.estoque.tipos_transferencia', ['transferencia']);

        $entradasQtd = (int) ((clone $baseMov)->whereIn('tipo', $tiposEntrada)->sum('quantidade') ?? 0);
        $saidasQtd = (int) ((clone $baseMov)->whereIn('tipo', $tiposSaida)->sum('quantidade') ?? 0);
        $transferenciasQtd = (int) ((clone $baseMov)->whereIn('tipo', $tiposTransferencia)->sum('quantidade') ?? 0);

        $estoqueBaixoQtd = $this->estoqueBaixoQtd($depositoId);
        $itensEntregaPendenteQtd = $this->itensEntregaPendenteQtd($depositoId);
        $consignacoesVencendoQtd = $this->consignacoesVencendoQtd($depositoId);

        $ultimasMovimentacoes = (clone $baseMov)
            ->leftJoin('produto_variacoes', 'produto_variacoes.id', '=', 'estoque_movimentacoes.id_variacao')
            ->leftJoin('produtos', 'produtos.id', '=', 'produto_variacoes.produto_id')
            ->select([
                'estoque_movimentacoes.id',
                'estoque_movimentacoes.tipo',
                'estoque_movimentacoes.quantidade',
                'estoque_movimentacoes.data_movimentacao',
                'estoque_movimentacoes.id_deposito_origem',
                'estoque_movimentacoes.id_deposito_destino',
                'produto_variacoes.sku_interno',
                'produto_variacoes.referencia',
                'produtos.codigo_produto',
                'produtos.nome as produto_nome',
            ])
            ->orderByDesc('estoque_movimentacoes.data_movimentacao')
            ->orderByDesc('estoque_movimentacoes.id')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'tipo' => $row->tipo,
                'quantidade' => (int) $row->quantidade,
                'data_movimentacao' => $row->data_movimentacao,
                'deposito_origem_id' => $row->id_deposito_origem ? (int) $row->id_deposito_origem : null,
                'deposito_destino_id' => $row->id_deposito_destino ? (int) $row->id_deposito_destino : null,
                'codigo_produto' => $row->codigo_produto,
                'sku_interno' => $row->sku_interno,
                'referencia' => $row->referencia,
                'identificador_variacao' => $row->sku_interno ?: $row->referencia,
                'produto_nome' => $row->produto_nome,
            ])
            ->values()
            ->all();

        return [
            'kpis' => [
                'estoque_baixo_qtd' => $estoqueBaixoQtd,
                'entradas_qtd' => $entradasQtd,
                'saidas_qtd' => $saidasQtd,
                'transferencias_qtd' => $transferenciasQtd,
            ],
            'pendencias' => [
                'itens_entrega_pendente_qtd' => $itensEntregaPendenteQtd,
                'consignacoes_vencendo_qtd' => $consignacoesVencendoQtd,
                'ultimas_movimentacoes' => $ultimasMovimentacoes,
            ],
        ];
    }

    private function estoqueBaixoQtd(?int $depositoId): int
    {
        $subquery = DB::table('estoque')
            ->join('produto_variacoes', 'estoque.id_variacao', '=', 'produto_variacoes.id')
            ->selectRaw('produto_variacoes.produto_id, SUM(estoque.quantidade) as quantidade_total')
            ->groupBy('produto_variacoes.produto_id');

        if ($depositoId) {
            $subquery->where('estoque.id_deposito', $depositoId);
        }

        return (int) DB::table('produtos')
            ->joinSub($subquery, 'estoque_total', function ($join) {
                $join->on('produtos.id', '=', 'estoque_total.produto_id');
            })
            ->whereColumn('estoque_total.quantidade_total', '<', 'produtos.estoque_minimo')
            ->count('produtos.id');
    }

    private function itensEntregaPendenteQtd(?int $depositoId): int
    {
        $query = DB::table('pedido_itens')
            ->where('entrega_pendente', 1)
            ->whereNull('data_liberacao_entrega');

        if ($depositoId) {
            $query->where('id_deposito', $depositoId);
        }

        return (int) ($query->count('id') ?? 0);
    }

    private function consignacoesVencendoQtd(?int $depositoId): int
    {
        $dias = (int) config('dashboard.consignacoes.dias_vencendo', 2);
        $limite = now()->addDays($dias)->toDateString();

        $query = DB::table('consignacoes')
            ->where('status', 'pendente')
            ->whereDate('prazo_resposta', '<=', $limite);

        if ($depositoId) {
            $query->where('deposito_id', $depositoId);
        }

        return (int) ($query->count('id') ?? 0);
    }
}
