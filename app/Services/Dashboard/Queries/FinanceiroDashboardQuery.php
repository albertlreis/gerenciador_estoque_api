<?php

namespace App\Services\Dashboard\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FinanceiroDashboardQuery
{
    public function fetch(): array
    {
        $hoje = now()->toDateString();

        $receberBase = DB::table('contas_receber')
            ->leftJoin('pedidos', 'pedidos.id', '=', 'contas_receber.pedido_id')
            ->leftJoin('clientes', 'clientes.id', '=', 'pedidos.id_cliente')
            ->whereNull('contas_receber.deleted_at')
            ->where('contas_receber.status', '!=', 'PAGA')
            ->whereDate('contas_receber.data_vencimento', '<', $hoje)
            ->where('contas_receber.saldo_aberto', '>', 0);

        $receberVencidoValor = (float) ((clone $receberBase)->sum('contas_receber.saldo_aberto') ?? 0);
        $receberVencidoQtd = (int) ((clone $receberBase)->count('contas_receber.id') ?? 0);

        $topReceberVencidos = (clone $receberBase)
            ->select([
                'contas_receber.id',
                'contas_receber.descricao',
                'clientes.nome as cliente_nome',
                'contas_receber.data_vencimento',
                'contas_receber.saldo_aberto',
            ])
            ->orderBy('contas_receber.data_vencimento')
            ->orderByDesc('contas_receber.saldo_aberto')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'titulo' => $row->descricao,
                'cliente' => $row->cliente_nome,
                'vencimento' => $row->data_vencimento,
                'saldo_aberto' => (float) $row->saldo_aberto,
            ])
            ->values()
            ->all();

        $pagarBase = $this->pagarVencidoBaseQuery($hoje);

        $saldoExpr = $this->saldoPagarExpression();

        $pagarVencidoValor = (float) ((clone $pagarBase)
            ->selectRaw("SUM(GREATEST({$saldoExpr}, 0)) as total")
            ->value('total') ?? 0);

        $pagarVencidoQtd = (int) ((clone $pagarBase)->count('contas_pagar.id') ?? 0);

        $topPagarVencidos = (clone $pagarBase)
            ->selectRaw("contas_pagar.id, contas_pagar.descricao, fornecedores.nome as fornecedor_nome, contas_pagar.data_vencimento, GREATEST({$saldoExpr}, 0) as saldo_aberto")
            ->orderBy('contas_pagar.data_vencimento')
            ->orderByDesc('saldo_aberto')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'titulo' => $row->descricao,
                'fornecedor' => $row->fornecedor_nome,
                'vencimento' => $row->data_vencimento,
                'saldo_aberto' => (float) $row->saldo_aberto,
            ])
            ->values()
            ->all();

        return [
            'kpis' => [
                'receber_vencido_valor' => $receberVencidoValor,
                'receber_vencido_qtd' => $receberVencidoQtd,
                'pagar_vencido_valor' => $pagarVencidoValor,
                'pagar_vencido_qtd' => $pagarVencidoQtd,
            ],
            'pendencias' => [
                'top_receber_vencidos' => $topReceberVencidos,
                'top_pagar_vencidos' => $topPagarVencidos,
            ],
        ];
    }

    private function pagarVencidoBaseQuery(string $hoje): Builder
    {
        $pagamentosSub = DB::table('contas_pagar_pagamentos')
            ->selectRaw('conta_pagar_id, SUM(valor) as valor_pago')
            ->groupBy('conta_pagar_id');

        $saldoExpr = $this->saldoPagarExpression();

        return DB::table('contas_pagar')
            ->leftJoinSub($pagamentosSub, 'pagamentos', function ($join) {
                $join->on('pagamentos.conta_pagar_id', '=', 'contas_pagar.id');
            })
            ->leftJoin('fornecedores', 'fornecedores.id', '=', 'contas_pagar.fornecedor_id')
            ->whereNull('contas_pagar.deleted_at')
            ->where('contas_pagar.status', '!=', 'PAGA')
            ->whereDate('contas_pagar.data_vencimento', '<', $hoje)
            ->whereRaw("{$saldoExpr} > 0");
    }

    private function saldoPagarExpression(): string
    {
        return '(contas_pagar.valor_bruto - contas_pagar.desconto + contas_pagar.juros + contas_pagar.multa) - COALESCE(pagamentos.valor_pago, 0)';
    }
}
