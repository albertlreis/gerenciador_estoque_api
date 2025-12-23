<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatório de Contas a Receber</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        th { background-color: #f5f5f5; }
        .total { font-weight: bold; background: #efefef; }
    </style>
</head>
<body>
<h2>Contas a Receber</h2>
<p>Gerado em: {{ $dataGeracao }}</p>

<table>
    <thead>
    <tr>
        <th>ID</th>
        <th>Cliente</th>
        <th>Pedido</th>
        <th>Descrição</th>
        <th>Emissão</th>
        <th>Vencimento</th>
        <th>Valor Líquido</th>
        <th>Recebido</th>
        <th>Saldo</th>
        <th>Status</th>
    </tr>
    </thead>
    <tbody>
    @foreach($contas as $conta)
        <tr>
            <td>{{ $conta->id }}</td>
            <td>{{ $conta->pedido->cliente->nome ?? '-' }}</td>
            <td>{{ $conta->pedido->numero ?? '-' }}</td>
            <td>{{ $conta->descricao }}</td>
            <td>{{ optional($conta->data_emissao)->format('d/m/Y') }}</td>
            <td>{{ optional($conta->data_vencimento)->format('d/m/Y') }}</td>
            <td>{{ number_format($conta->valor_liquido, 2, ',', '.') }}</td>
            <td>{{ number_format($conta->valor_recebido, 2, ',', '.') }}</td>
            <td>{{ number_format($conta->saldo_aberto, 2, ',', '.') }}</td>
            <td>{{ $conta->status }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
