<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Relatório de extrato</title>
    <style>
        @page { size: A4 landscape; margin: 14mm 10mm 16mm 10mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #4b5563; font-size: 11px; margin: 0; }
        .header { width: 100%; margin-bottom: 18px; }
        .title { font-size: 22px; color: #111827; font-weight: 700; display: inline-block; margin-right: 8px; }
        .period { font-size: 16px; color: #4b5563; }
        .top { width: 100%; border-collapse: collapse; margin-top: 22px; }
        .top td { vertical-align: top; border: 0; padding: 0; }
        .logo-cell { width: 130px; text-align: center; padding-top: 18px; }
        .logo { width: 92px; }
        .company { font-size: 15px; line-height: 1.7; }
        .company strong { display: block; font-size: 17px; color: #4b5563; letter-spacing: .5px; }
        .summary { background: #f1f5f9; padding: 14px 18px; width: 330px; margin-left: auto; font-size: 14px; line-height: 1.65; }
        .summary-row { width: 100%; }
        .summary-row span { display: inline-block; width: 72%; }
        .summary-row strong { display: inline-block; width: 27%; text-align: right; color: #374151; }
        .account { margin: 18px 0 12px 2px; font-size: 13px; }
        .account strong { color: #374151; }
        table.extract { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .extract th { background: #f1f5f9; color: #374151; font-weight: 700; padding: 7px 8px; text-align: left; }
        .extract td { padding: 5px 8px; border-bottom: 2px solid #e5e7eb; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .right { text-align: right; }
        .status { display: block; background: #d9fbd3; color: #065f46; font-weight: 700; text-align: center; padding: 2px 4px; }
        .negative { color: #ef1f2f; }
        .totals { background: #f1f5f9; margin-top: 4px; padding: 12px 12px 8px; width: 100%; border-collapse: collapse; }
        .totals td { border: 0; }
        .totals-title { font-size: 16px; font-weight: 700; color: #374151; }
        .totals-sub { font-size: 13px; margin-top: 4px; }
        .footer { position: fixed; left: 10mm; right: 10mm; bottom: 7mm; font-size: 11px; color: #374151; }
        .footer .center { text-align: center; color: #258bd2; font-size: 18px; font-weight: 700; }
        .footer .right { position: absolute; right: 0; bottom: 0; }
        .footer .left { position: absolute; left: 0; bottom: 0; }
    </style>
</head>
<body>
<div class="header">
    <span class="title">Relatório de extrato</span>
    <span class="period">{{ $periodo['inicio'] }} a {{ $periodo['fim'] }}</span>

    <table class="top">
        <tr>
            <td class="logo-cell">
                <img class="logo" src="{{ !extension_loaded('gd') ? 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="80" viewBox="0 0 120 80"><text x="60" y="28" text-anchor="middle" font-family="serif" font-size="24" fill="#111">SR</text><text x="60" y="52" text-anchor="middle" font-family="serif" font-size="24" fill="#111">SIERRA</text><text x="60" y="66" text-anchor="middle" font-family="sans-serif" font-size="7" fill="#555">MOVEIS</text></svg>') : public_path('logo.png') }}" alt="Sierra">
            </td>
            <td class="company">
                <strong>{{ $empresa['nome'] }}</strong>
                {{ $empresa['endereco'] }}<br>
                {{ $empresa['documento'] }}<br>
                {{ $empresa['telefone'] }}
            </td>
            <td>
                <div class="summary">
                    <div class="summary-row"><span>Receitas Em Aberto (R$)</span><strong>{{ number_format($resumo['receitas_abertas'], 2, ',', '.') }}</strong></div>
                    <div class="summary-row"><span>Receitas Realizadas (R$)</span><strong>{{ number_format($resumo['receitas_realizadas'], 2, ',', '.') }}</strong></div>
                    <div class="summary-row"><span>Despesas Em Aberto (R$)</span><strong>{{ number_format($resumo['despesas_abertas'], 2, ',', '.') }}</strong></div>
                    <div class="summary-row"><span>Despesas Realizadas (R$)</span><strong>{{ number_format($resumo['despesas_realizadas'], 2, ',', '.') }}</strong></div>
                    <div class="summary-row"><span>Totais do Período (R$)</span><strong>{{ number_format($resumo['total_periodo'], 2, ',', '.') }}</strong></div>
                </div>
            </td>
        </tr>
    </table>
</div>

<div class="account"><strong>Conta:</strong> {{ $conta->nome }}</div>

<table class="extract">
    <thead>
    <tr>
        <th style="width: 9%">Data</th>
        <th style="width: 23%">Descrição</th>
        <th style="width: 19%">Cliente/Fornecedor</th>
        <th style="width: 12%">Situação</th>
        <th style="width: 21%">Categoria</th>
        <th style="width: 8%" class="right">Valor (R$)</th>
        <th style="width: 8%" class="right">Saldo (R$)</th>
    </tr>
    </thead>
    <tbody>
    @forelse($linhas as $linha)
        <tr>
            <td>{{ $linha['data'] }}</td>
            <td>{{ $linha['descricao'] }}</td>
            <td>{{ $linha['cliente_fornecedor'] }}</td>
            <td><span class="status">{{ $linha['situacao'] }}</span></td>
            <td>{{ $linha['categoria'] }}</td>
            <td class="right {{ $linha['valor'] < 0 ? 'negative' : '' }}">{{ number_format($linha['valor'], 2, ',', '.') }}</td>
            <td class="right">{{ number_format($linha['saldo'], 2, ',', '.') }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="7">Nenhum movimento encontrado no período.</td>
        </tr>
    @endforelse
    </tbody>
</table>

<table class="totals">
    <tr>
        <td style="width: 58%">
            <div class="totals-title">Totais do período</div>
            <div class="totals-sub">De {{ $periodo['inicio'] }} a {{ $periodo['fim'] }}</div>
        </td>
        <td class="right">Desconsiderados<br><strong>R$ {{ number_format($resumo['desconsiderados'], 2, ',', '.') }}</strong></td>
        <td class="right">Perdidos<br><strong>R$ {{ number_format($resumo['perdidos'], 2, ',', '.') }}</strong></td>
        <td class="right">Saldo Realizado<br><strong>R$ {{ number_format($resumo['saldo_realizado'], 2, ',', '.') }}</strong></td>
    </tr>
</table>

<div class="footer">
    <div class="left"><strong>Usuário:</strong> {{ $usuario }}</div>
    <div class="center">Sierra</div>
    <div class="right"></div>
</div>
<script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->getFont("DejaVu Sans", "normal");
        $pdf->page_text($pdf->get_width() - 100, $pdf->get_height() - 24, "Página {PAGE_NUM} de {PAGE_COUNT}", $font, 9, [0.22, 0.26, 0.32]);
    }
</script>
</body>
</html>
