<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Pedidos Detalhado</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; }
        h2 { text-align: center; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        th { background-color: #f0f0f0; }
        .produtos-table { margin: 5px 0 15px 30px; width: 90%; }
    </style>
</head>
<body>
<h2>Relatório de Pedidos Detalhado</h2>

@foreach ($pedidos as $pedido)
    <table>
        <tr>
            <th>Nº Pedido</th>
            <td>{{ $pedido->numero }}</td>
            <th>Data</th>
            <td>{{ $pedido->data }}</td>
        </tr>
        <tr>
            <th>Cliente</th>
            <td>{{ $pedido->cliente->nome ?? '' }}</td>
            <th>Parceiro</th>
            <td>{{ $pedido->parceiro->nome ?? '' }}</td>
        </tr>
        <tr>
            <th>Total</th>
            <td>R$ {{ number_format($pedido->total, 2, ',', '.') }}</td>
            <th>Status</th>
            <td>{{ ucfirst($pedido->status) }}</td>
        </tr>
        @if ($pedido->observacoes)
            <tr>
                <th colspan=\"1\">Observações</th>
                <td colspan=\"3\">{{ $pedido->observacoes }}</td>
            </tr>
        @endif
    </table>

    @if ($pedido->produtos && count($pedido->produtos))
        <table class=\"produtos-table\">
            <thead>
            <tr>
                <th>Produto</th>
                <th>Quantidade</th>
                <th>Valor Unitário</th>
                <th>Total</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($pedido->produtos as $produto)
                <tr>
                    <td>{{ $produto->nome }}</td>
                    <td>{{ $produto->pivot->quantidade }}</td>
                    <td>R$ {{ number_format($produto->pivot->valor, 2, ',', '.') }}</td>
                    <td>R$ {{ number_format($produto->pivot->quantidade * $produto->pivot->valor, 2, ',', '.') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
    <hr>
@endforeach
</body>
</html>
