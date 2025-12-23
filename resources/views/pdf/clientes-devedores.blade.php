<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatório de Clientes Devedores</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        th { background-color: #f5f5f5; }
        .total { background: #efefef; font-weight: bold; }
        h2 { margin-bottom: 5px; }
    </style>
</head>
<body>
<h2>Relatório de Clientes Devedores</h2>
<p>Gerado em: {{ $geradoEm }}</p>

<table>
    <thead>
    <tr>
        <th>Cliente</th>
        <th>Títulos</th>
        <th>Total Líquido</th>
        <th>Recebido</th>
        <th>Aberto</th>
        <th>Vencido</th>
        <th>Último Vencimento</th>
    </tr>
    </thead>
    <tbody>
    @foreach($dados as $d)
        <tr>
            <td>{{ $d->cliente_nome }}</td>
            <td>{{ $d->qtd_titulos }}</td>
            <td>{{ number_format($d->total_liquido, 2, ',', '.') }}</td>
            <td>{{ number_format($d->total_recebido, 2, ',', '.') }}</td>
            <td>{{ number_format($d->total_aberto, 2, ',', '.') }}</td>
            <td>{{ number_format($d->total_vencido, 2, ',', '.') }}</td>
            <td>{{ $d->ultimo_vencimento ? date('d/m/Y', strtotime($d->ultimo_vencimento)) : '-' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
