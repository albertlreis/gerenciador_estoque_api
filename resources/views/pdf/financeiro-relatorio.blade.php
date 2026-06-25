<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $dados['titulo'] ?? 'Relatório financeiro' }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm 10mm 14mm 10mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #334155; font-size: 10px; margin: 0; }
        .header { display: table; width: 100%; margin-bottom: 14px; }
        .header-main { display: table-cell; vertical-align: top; }
        .brand { display: table-cell; width: 100px; text-align: right; color: #0f172a; font-weight: 700; font-size: 18px; }
        h1 { color: #0f172a; font-size: 20px; margin: 0 0 4px; }
        .period { color: #64748b; font-size: 12px; }
        .kpis { width: 100%; border-collapse: separate; border-spacing: 6px; margin-bottom: 12px; }
        .kpis td { background: #f8fafc; border: 1px solid #e2e8f0; padding: 8px; }
        .kpis span { display: block; color: #64748b; font-size: 9px; text-transform: uppercase; }
        .kpis strong { display: block; margin-top: 3px; color: #0f172a; font-size: 13px; }
        table.report { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .report th { background: #eef6fb; color: #0f172a; padding: 7px 6px; text-align: left; font-weight: 700; border-bottom: 1px solid #cbd5e1; }
        .report td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .report tr.subtotal td { background: #f8fafc; color: #0f172a; font-weight: 700; }
        .right { text-align: right; }
        .footer { position: fixed; left: 10mm; right: 10mm; bottom: 6mm; color: #64748b; font-size: 9px; }
    </style>
</head>
<body>
@php($periodo = $dados['periodo'] ?? [])
<div class="header">
    <div class="header-main">
        <h1>{{ $dados['titulo'] ?? 'Relatório financeiro' }}</h1>
        <div class="period">{{ $periodo['inicio_label'] ?? '-' }} a {{ $periodo['fim_label'] ?? '-' }}</div>
    </div>
    <div class="brand">Sierra</div>
</div>

@php($kpis = $dados['kpis'] ?? [])
@if(count($kpis))
    <table class="kpis">
        <tr>
            @foreach($kpis as $label => $valor)
                <td>
                    <span>{{ ucfirst(str_replace('_', ' ', $label)) }}</span>
                    <strong>{{ is_numeric($valor) ? number_format((float) $valor, 2, ',', '.') : $valor }}</strong>
                </td>
            @endforeach
        </tr>
    </table>
@endif

@php($colunas = $dados['colunas'] ?? [])
<table class="report">
    <thead>
    <tr>
        @foreach($colunas as $coluna)
            <th>{{ $coluna['header'] ?? $coluna['field'] }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @forelse(($dados['linhas'] ?? []) as $linha)
        <tr class="{{ !empty($linha['subtotal']) ? 'subtotal' : '' }}">
            @foreach($colunas as $coluna)
                @php($field = $coluna['field'])
                @php($valor = $linha[$field] ?? '')
                <td class="{{ is_numeric($valor) ? 'right' : '' }}">
                    {{ is_numeric($valor) ? number_format((float) $valor, 2, ',', '.') : $valor }}
                </td>
            @endforeach
        </tr>
    @empty
        <tr>
            <td colspan="{{ max(count($colunas), 1) }}">Nenhum dado encontrado no período.</td>
        </tr>
    @endforelse
    </tbody>
</table>

<div class="footer">
    Gerado em {{ $dados['gerado_em'] ?? now()->format('Y-m-d H:i:s') }}
</div>
<script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->getFont("DejaVu Sans", "normal");
        $pdf->page_text($pdf->get_width() - 100, $pdf->get_height() - 24, "Página {PAGE_NUM} de {PAGE_COUNT}", $font, 9, [0.38, 0.45, 0.55]);
    }
</script>
</body>
</html>
