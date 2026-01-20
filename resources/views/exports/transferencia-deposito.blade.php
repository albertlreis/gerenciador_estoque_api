@php
    use Carbon\Carbon;
    $dt = $transferencia->created_at ? Carbon::parse($transferencia->created_at)->format('d/m/Y H:i') : '-';
@endphp
    <!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Transferência entre Depósitos</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; color: #000; }
        .header { text-align:center; margin-bottom: 10px; }
        .title { font-size: 16px; font-weight: bold; }
        .muted { color:#444; font-size: 10px; }
        .box { border: 1px solid #000; padding: 8px; margin-bottom: 8px; }
        .grid { width:100%; border-collapse: collapse; }
        .grid td { padding: 4px; vertical-align: top; }
        .table { width:100%; border-collapse: collapse; }
        .table th, .table td { border: 1px solid #ccc; padding: 4px; font-size: 10px; }
        .table th { background: #f3c000; text-transform: uppercase; font-weight: bold; }
        .sign { margin-top: 10px; }
        .sign td { border: none; padding-top: 18px; }
        .line { border-top: 1px solid #000; width:100%; height: 1px; }
    </style>
</head>
<body>
<div class="header">
    <div class="title">Transferência entre Depósitos</div>
    <div class="muted">Documento: {{ $transferencia->uuid }} • Data: {{ $dt }}</div>
</div>

<div class="box">
    <table class="grid">
        <tr>
            <td><b>Origem:</b> {{ $transferencia->depositoOrigem?->nome ?? '-' }}</td>
            <td><b>Destino:</b> {{ $transferencia->depositoDestino?->nome ?? '-' }}</td>
        </tr>
        <tr>
            <td><b>Responsável:</b> {{ $transferencia->usuario?->nome ?? '-' }}</td>
            <td><b>Status:</b> {{ strtoupper($transferencia->status ?? '-') }}</td>
        </tr>
        <tr>
            <td colspan="2"><b>Observação:</b> {{ $transferencia->observacao ?? '-' }}</td>
        </tr>
        <tr>
            <td><b>Total de itens:</b> {{ $transferencia->total_itens ?? 0 }}</td>
            <td><b>Total de peças:</b> {{ $transferencia->total_pecas ?? 0 }}</td>
        </tr>
    </table>
</div>

<table class="table">
    <thead>
    <tr>
        <th style="width: 70px;">Qtd</th>
        <th>Produto / Variação</th>
        <th style="width: 120px;">Ref.</th>
        <th style="width: 160px;">Localização (Origem)</th>
        <th style="width: 70px;">OK</th>
    </tr>
    </thead>
    <tbody>
    @foreach($transferencia->itens as $item)
        @php
            $v = $item->variacao;
            $nome = $v?->nome_completo ?? ($v?->produto?->nome ?? '-');
            $ref = $v?->referencia ?? '-';
            $locParts = array_filter([$item->corredor, $item->prateleira, $item->nivel]);
            $loc = count($locParts) ? implode(' / ', $locParts) : '-';
        @endphp
        <tr>
            <td style="text-align:center;"><b>{{ $item->quantidade }}</b></td>
            <td>{{ $nome }}</td>
            <td>{{ $ref }}</td>
            <td>{{ $loc }}</td>
            <td></td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="sign" style="width:100%;">
    <tr>
        <td style="width:33%;">
            <div class="line"></div>
            <div class="muted">Separado por</div>
        </td>
        <td style="width:33%;">
            <div class="line"></div>
            <div class="muted">Conferido por</div>
        </td>
        <td style="width:33%;">
            <div class="line"></div>
            <div class="muted">Recebido no destino</div>
        </td>
    </tr>
</table>
</body>
</html>
