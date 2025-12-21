<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px; }
        th { background: #f3f3f3; }
    </style>
</head>
<body>

<h2>Estoque Atual</h2>

<table>
    <thead>
    <tr>
        <th>Produto</th>
        <th>Referência</th>

        @if(!$filtros->zerados)
            <th>Depósito</th>
            <th>Quantidade</th>
            <th>Localização</th>
        @else
            <th>Quantidade Total</th>
        @endif
    </tr>
    </thead>
    <tbody>

    @foreach($estoque as $item)

        @if($filtros->zerados)
            <tr>
                <td>{{ $item->nome_completo }}</td>
                <td>{{ $item->referencia }}</td>
                <td>{{ $item->quantidade_estoque ?? 0 }}</td>
            </tr>
        @else
            @foreach($item->estoquesComLocalizacao as $estoqueItem)
                <tr>
                    <td>{{ $item->nome_completo }}</td>
                    <td>{{ $item->referencia }}</td>
                    <td>{{ $estoqueItem->deposito->nome ?? '-' }}</td>
                    <td>{{ $estoqueItem->quantidade }}</td>
                    <td>
                        {{ $estoqueItem->localizacao->codigo_composto
                            ?? $estoqueItem->localizacao->area->nome
                            ?? '-' }}
                    </td>
                </tr>
            @endforeach
        @endif

    @endforeach

    </tbody>
</table>

</body>
</html>
