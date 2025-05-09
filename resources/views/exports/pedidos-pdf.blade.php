<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Pedidos</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; }
        h2 { text-align: center; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        th { background-color: #f0f0f0; }
    </style>
</head>
<body>
<h2>Relatório de Pedidos</h2>
<table>
    <thead>
    <tr>
        <th>Nº Pedido</th>
        <th>Data</th>
        <th>Cliente</th>
        <th>Parceiro</th>
        <th>Total</th>
        <th>Status</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($pedidos as $pedido)
        <tr>
            <td>{{ $pedido->numero }}</td>
            <td>{{ $pedido->data }}</td>
            <td>{{ $pedido->cliente->nome ?? '' }}</td>
            <td>{{ $pedido->parceiro->nome ?? '' }}</td>
            <td>R$ {{ number_format($pedido->total, 2, ',', '.') }}</td>
            <td>{{ ucfirst($pedido->status) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
