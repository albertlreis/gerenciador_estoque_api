<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Cat?logo Outlet</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; color: #000; }
        .header { text-align: center; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px; font-size: 10px; vertical-align: top; }
        th { background: #f3c000; text-transform: uppercase; font-weight: bold; }
        .nowrap { white-space: nowrap; }
        .wrap { word-break: break-word; overflow-wrap: anywhere; white-space: normal; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
    </style>
</head>
<body>
<div class="header">
    <img src="{{ public_path('logo.png') }}" width="120" alt="Logo"/>
    <h3>CAT?LOGO OUTLET</h3>
</div>

<table>
    <thead>
    <tr>
        <th style="width: 90px;">Img</th>
        <th style="width: 120px;">Refer?ncia</th>
        <th>Nome</th>
        <th style="width: 140px;">Categoria</th>
        <th style="width: 90px;">Pre?o normal</th>
        <th style="width: 90px;">Desconto</th>
        <th style="width: 90px;">Pre?o outlet</th>
    </tr>
    </thead>
    <tbody>
    @foreach($produtos as $produto)
        @php
            $variacoes = $produto->variacoes ?? collect();
            $refs = $variacoes->pluck('referencia')->filter()->unique()->implode(', ');

            $precos = $variacoes->pluck('preco')->filter(fn($v) => $v !== null);
            $precoMin = $precos->isNotEmpty() ? (float) $precos->min() : null;

            $descontos = $variacoes->flatMap(function ($v) {
                return ($v->outlets ?? collect())->flatMap(function ($o) {
                    return ($o->formasPagamento ?? collect())->pluck('percentual_desconto');
                });
            })->filter(fn($v) => $v !== null);

            $descontoMax = $descontos->isNotEmpty() ? (float) $descontos->max() : null;
            $precoOutlet = ($precoMin !== null && $descontoMax !== null)
                ? $precoMin * (1 - ($descontoMax / 100))
                : null;

            $imgRel = optional($produto?->imagemPrincipal)->url ?? '';
            $imgAbs = ($imgRel && !empty($baseFsDir ?? null))
                ? ($baseFsDir . DIRECTORY_SEPARATOR . $imgRel)
                : '';
        @endphp
        <tr>
            <td style="text-align:center;">
                @if($imgAbs)
                    <img src="{{ $imgAbs }}" width="80" style="max-height:64px;" alt="Imagem produto"/>
                @endif
            </td>
            <td class="wrap">{{ $refs ?: '?' }}</td>
            <td class="wrap">{{ $produto->nome ?? '?' }}</td>
            <td class="wrap">{{ $produto->categoria?->nome ?? '?' }}</td>
            <td class="nowrap">
                {{ $precoMin !== null ? number_format($precoMin, 2, ',', '.') : '?' }}
            </td>
            <td class="nowrap">
                {{ $descontoMax !== null ? number_format($descontoMax, 2, ',', '.') . '%' : '?' }}
            </td>
            <td class="nowrap">
                {{ $precoOutlet !== null ? number_format($precoOutlet, 2, ',', '.') : '?' }}
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
