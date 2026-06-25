<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18px 20px; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #172033;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            line-height: 1.28;
        }
        .report-header {
            display: table;
            width: 100%;
            margin-bottom: 10px;
            border-bottom: 1px solid #dbe4ef;
            padding-bottom: 8px;
        }
        .report-title,
        .report-meta {
            display: table-cell;
            vertical-align: bottom;
        }
        h1 {
            margin: 0 0 3px;
            color: #0f172a;
            font-size: 17px;
        }
        .muted { color: #64748b; }
        .report-meta {
            text-align: right;
            white-space: nowrap;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        th,
        td {
            border: 1px solid #dbe4ef;
            padding: 5px 6px;
            vertical-align: top;
        }
        th {
            background: #f1f5f9;
            color: #334155;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
        }
        tbody tr:nth-child(even) td { background: #fbfdff; }
        .date { width: 10%; white-space: nowrap; }
        .summary { width: 34%; word-break: break-word; }
        .money { width: 12%; text-align: right; white-space: nowrap; }
        .status { width: 10%; text-align: center; white-space: nowrap; }
        .summary strong {
            display: block;
            margin-bottom: 2px;
            color: #0f172a;
            font-size: 9px;
        }
        .summary span {
            display: block;
            color: #64748b;
            font-size: 8px;
        }
        tfoot td {
            background: #f8fafc;
            font-weight: 700;
        }
        .empty {
            padding: 24px;
            color: #64748b;
            text-align: center;
        }
    </style>
</head>
<body>
@php
    $totalLiquido = 0.0;
    $totalPago = 0.0;
    $totalSaldo = 0.0;
@endphp
<div class="report-header">
    <div class="report-title">
        <h1>Contas a Pagar</h1>
        <div class="muted">Relatorio resumido dos titulos exportados</div>
    </div>
    <div class="report-meta">
        <strong>Gerado em</strong><br>
        <span class="muted">{{ $gerado_em }}</span>
    </div>
</div>

<table>
    <thead>
    <tr>
        <th class="date">Vencimento</th>
        <th class="date">Pagamento</th>
        <th class="summary">Resumo do lancamento</th>
        <th class="money">Total (R$)</th>
        <th class="money">Pago (R$)</th>
        <th class="money">A pagar (R$)</th>
        <th class="status">Situacao</th>
    </tr>
    </thead>
    <tbody>
    @forelse($linhas as $c)
        @php
            $pagamentos = $c->pagamentos ?? collect();
            $ultimoPagamento = $pagamentos->sortByDesc('data_pagamento')->first();
            $liquido = (float) $c->valor_bruto - (float) $c->desconto + (float) $c->juros + (float) $c->multa;
            $pago = (float) $pagamentos->sum('valor');
            $saldo = max(0, $liquido - $pago);
            $totalLiquido += $liquido;
            $totalPago += $pago;
            $totalSaldo += $saldo;
            $fornecedor = $c->fornecedor?->nome;
            $doc = $c->numero_documento ? 'Doc ' . $c->numero_documento : null;
            $detalhes = collect([$fornecedor, $doc])->filter()->implode(' | ');
        @endphp
        <tr>
            <td class="date">{{ optional($c->data_vencimento)->format('d/m/Y') ?: '-' }}</td>
            <td class="date">{{ optional($ultimoPagamento?->data_pagamento)->format('d/m/Y') ?: '-' }}</td>
            <td class="summary">
                <strong>{{ $c->descricao ?: '-' }}</strong>
                <span>{{ $detalhes ?: 'Sem detalhes' }}</span>
            </td>
            <td class="money">{{ number_format($liquido, 2, ',', '.') }}</td>
            <td class="money">{{ number_format($pago, 2, ',', '.') }}</td>
            <td class="money">{{ number_format($saldo, 2, ',', '.') }}</td>
            <td class="status">{{ $c->status?->value ?? $c->status }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="7" class="empty">Nenhum resultado encontrado</td>
        </tr>
    @endforelse
    </tbody>
    <tfoot>
    <tr>
        <td colspan="3">Totais do periodo</td>
        <td class="money">{{ number_format($totalLiquido, 2, ',', '.') }}</td>
        <td class="money">{{ number_format($totalPago, 2, ',', '.') }}</td>
        <td class="money">{{ number_format($totalSaldo, 2, ',', '.') }}</td>
        <td></td>
    </tr>
    </tfoot>
</table>
</body>
</html>
