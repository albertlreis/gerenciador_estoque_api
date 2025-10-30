<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 6px; }
        th { background: #f2f2f2; }
        .right { text-align: right; }
    </style>
</head>
<body>
<h3>Contas a Pagar</h3>
<p>Gerado em: {{ $gerado_em }}</p>
<table>
    <thead>
    <tr>
        <th>#</th><th>Descrição</th><th>Nº Doc</th><th>Emissão</th><th>Vencimento</th>
        <th class="right">Bruto</th><th class="right">Desc</th><th class="right">Juros</th><th class="right">Multa</th><th class="right">Líquido</th><th>Status</th>
    </tr>
    </thead>
    <tbody>
    @foreach($linhas as $c)
        @php($liq = $c->valor_bruto - $c->desconto + $c->juros + $c->multa)
        <tr>
            <td>{{ $c->id }}</td>
            <td>{{ $c->descricao }}</td>
            <td>{{ $c->numero_documento }}</td>
            <td>{{ optional($c->data_emissao)->format('d/m/Y') }}</td>
            <td>{{ optional($c->data_vencimento)->format('d/m/Y') }}</td>
            <td class="right">{{ number_format($c->valor_bruto,2,',','.') }}</td>
            <td class="right">{{ number_format($c->desconto,2,',','.') }}</td>
            <td class="right">{{ number_format($c->juros,2,',','.') }}</td>
            <td class="right">{{ number_format($c->multa,2,',','.') }}</td>
            <td class="right">{{ number_format($liq,2,',','.') }}</td>
            <td>{{ $c->status }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
