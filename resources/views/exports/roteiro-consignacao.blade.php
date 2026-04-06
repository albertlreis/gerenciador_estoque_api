@php use Carbon\Carbon; @endphp
    <!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>{{ $tituloRoteiro ?? 'Roteiro de consignação' }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; color: #000; }
        .header, .footer { text-align: center; margin-bottom: 10px; }
        .section { border: 1px solid #000; margin-bottom: 8px; }
        .section-title { background-color: #f3c000; font-weight: bold; padding: 4px; text-transform: uppercase; }
        .section-content table { width: 100%; border-collapse: collapse; }
        .section-content th, .section-content td { border: 1px solid #ccc; padding: 4px; font-size: 10px; vertical-align: top; }
        .obs { border: 1px solid #000; padding: 5px; min-height: 50px; }
        .recebido {
            margin-top: 10px;
            border: 1px solid #000;
            font-weight: bold;
            text-align: center;
            color: red;
            min-height: 110px;
            display: flex;
            justify-content: center;
            align-items: flex-end;
            padding-bottom: 10px;
        }
        .section-content img { display: block; margin: auto; border: 1px solid #ccc; }
        .muted { color: #666; }
        .nowrap { white-space: nowrap; }
        .wrap { word-break: break-word; overflow-wrap: anywhere; white-space: normal; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
    </style>
</head>
<body>
<div class="header">
    <img src="{{ !extension_loaded('gd') ? 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="26" viewBox="0 0 120 26"><rect width="120" height="26" rx="4" fill="#1f1a17"/><text x="60" y="17" text-anchor="middle" font-family="Arial, sans-serif" font-size="14" fill="#f5ede2">Sierra</text></svg>') : public_path('logo.png') }}" width="120" alt="Logo"/>
    <h3>{{ mb_strtoupper($tituloRoteiro ?? 'Roteiro de consignação') }}</h3>
</div>

@php
    $enderecoPrincipal = $pedido->cliente?->enderecoPrincipal ?? null;
    $enderecoTexto = $enderecoPrincipal
        ? trim(implode(' - ', array_filter([
            $enderecoPrincipal->logradouro ?? null,
            $enderecoPrincipal->numero ?? null,
            $enderecoPrincipal->bairro ?? null,
            $enderecoPrincipal->cidade ?? null,
            $enderecoPrincipal->uf ?? null,
        ])))
        : ($pedido->cliente?->endereco ?? '-'); // fallback caso exista coluna legada
@endphp

<table width="100%" style="margin-bottom: 10px;">
    <tr>
        <td class="nowrap"><strong>VENDEDOR(A):</strong> {{ $pedido->usuario->nome ?? '-' }}</td>
        <td class="wrap"><strong>PARCEIRO:</strong> {{ $pedido->parceiro->nome ?? '—' }}</td>
    </tr>
    <tr>
        <td class="wrap"><strong>CLIENTE:</strong> {{ $pedido->cliente->nome ?? '-' }}</td>
        <td class="wrap"><strong>END:</strong> {{ $enderecoTexto ?: '-' }}</td>
    </tr>
    <tr>
        <td class="nowrap"><strong>TEL:</strong> {{ $pedido->cliente->telefone ?? '-' }}</td>
        <td class="nowrap"><strong>PEDIDO:</strong> {{ $pedido->numero_externo ?? $pedido->id ?? '-' }}</td>
    </tr>
</table>

@foreach ($grupos as $deposito => $itens)
    <div class="section">
        <div class="section-title">{{ strtoupper($deposito) }}</div>
        <div class="section-content">
            <table>
                <thead>
                <tr>
                    <th style="width: 90px;">IMG</th>
                    <th style="width: 45px;">QTD</th>
                    <th style="width: 80px;">REF</th>
                    <th>DESCRIÇÃO</th>
                    <th style="width: 100px;">LOCALIZAÇÃO</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($itens as $item)
                    @php
                        $variacao   = $item->produtoVariacao;
                        $produto    = $variacao?->produto;
                        $referencia = $variacao?->referencia ?? '-';
                        $descricao  = $variacao?->nome_completo ?? '-';

                        $imgRel = optional($produto?->imagemPrincipal)->url ?? '';
                        $imgAbs = ($imgRel && !empty($baseFsDir ?? null))
                            ? ($baseFsDir . DIRECTORY_SEPARATOR . $imgRel)
                            : '';

                        // LOCALIZAÇÃO: Estoque.id_deposito (corrigido!) x item->deposito_id (Consignacao)
                        $locTexto = '—';
                        $estoques = $variacao && $variacao->relationLoaded('estoquesComLocalizacao')
                            ? $variacao->estoquesComLocalizacao
                            : collect();

                        $depositoId = (int)($item->deposito_id ?? 0);
                        if ($depositoId > 0 && $estoques->count()) {
                            $estoqueDoDeposito = $estoques->first(fn($e) => (int)($e->id_deposito ?? 0) === $depositoId);

                            if ($estoqueDoDeposito && $estoqueDoDeposito->localizacao) {
                                $loc = $estoqueDoDeposito->localizacao;

                                if (!empty($loc->codigo_composto)) {
                                    $locTexto = $loc->codigo_composto;
                                } else {
                                    $partes = [];
                                    if (!empty($loc->setor))  $partes[] = 'Setor: ' . $loc->setor;
                                    if (!empty($loc->coluna)) $partes[] = 'Coluna: ' . $loc->coluna;
                                    if (!empty($loc->nivel))  $partes[] = 'Nível: ' . $loc->nivel;
                                    if (!empty($loc->area?->nome)) $partes[] = 'Área: ' . $loc->area->nome;
                                    $locTexto = $partes ? implode(' | ', $partes) : '—';
                                }
                            }
                        }
                    @endphp
                    <tr>
                        <td style="text-align:center;">
                            @if($imgAbs)
                                <img src="{{ $imgAbs }}" width="80" style="max-height:64px;" alt="Imagem produto"/>
                            @endif
                        </td>
                        <td class="nowrap">{{ (int)($item->quantidade ?? 0) }}</td>
                        <td class="nowrap">{{ $referencia }}</td>
                        <td class="wrap">{{ $descricao }}</td>
                        <td class="wrap">{{ $locTexto }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endforeach

<div style="margin-top: 10px;"><strong>OBS:</strong></div>
<div class="obs">{{ $pedido->observacoes ?? '' }}</div>

<div class="recebido">
    <div>
        <br><br>RECEBIDO EM PERFEITO ESTADO NO ATO DA ENTREGA.<br><br><br><br>
        ASS: ________________________________________
    </div>
</div>

<div class="footer">
    Clemente Salheb / Joseane Cunha<br>
    <strong>Sierra Belém</strong>
</div>
</body>
</html>
