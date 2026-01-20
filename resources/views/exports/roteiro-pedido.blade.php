@php use Carbon\Carbon; @endphp
    <!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Roteiro do Pedido</title>
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
        .nowrap { white-space: nowrap; }
        .wrap { word-break: break-word; overflow-wrap: anywhere; white-space: normal; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .muted { color: #666; }
    </style>
</head>
<body>
<div class="header">
    <img src="{{ public_path('logo.png') }}" width="120" alt="Logo"/>
    <h3>ROTEIRO DO PEDIDO</h3>
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
        <td class="nowrap"><strong>PEDIDO:</strong> {{ $pedido->numero_externo ?? $pedido->id }}</td>
        <td class="nowrap"><strong>GERADO EM:</strong> {{ $geradoEm ?? '-' }}</td>
    </tr>
    <tr>
        <td class="nowrap"><strong>DATA:</strong> {{ $pedido->data_pedido ? Carbon::parse($pedido->data_pedido)->format('d/m/Y') : '-' }}</td>
        <td class="nowrap"><strong>VENDEDOR(A):</strong> {{ $pedido->usuario->nome ?? '-' }}</td>
    </tr>
    <tr>
        <td class="wrap"><strong>CLIENTE:</strong> {{ $pedido->cliente->nome ?? '-' }}</td>
        <td class="wrap"><strong>END:</strong> {{ $enderecoTexto ?: '-' }}</td>
    </tr>
    <tr>
        <td class="nowrap"><strong>TEL:</strong> {{ $pedido->cliente->telefone ?? '-' }}</td>
        <td class="wrap"><strong>PARCEIRO:</strong> {{ $pedido->parceiro->nome ?? '—' }}</td>
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
                    <th style="width: 160px;">OBS</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($itens as $item)
                    @php
                        $variacao   = $item->variacao;
                        $produto    = $variacao?->produto;
                        $referencia = $variacao?->referencia ?? '-';
                        $descricao  = $variacao?->nome_completo ?? '-';

                        // imagem principal (mesma lógica do roteiro-consignacao)
                        $imgRel = optional($produto?->imagemPrincipal)->url ?? '';
                        $imgAbs = ($imgRel && !empty($baseFsDir ?? null))
                            ? ($baseFsDir . DIRECTORY_SEPARATOR . $imgRel)
                            : '';

                        // localização: pega estoque da variação no depósito do item (Estoque.id_deposito)
                        $locTexto = '—';
                        $depositoId = (int)($item->id_deposito ?? 0);

                        $estoques = $variacao && $variacao->relationLoaded('estoquesComLocalizacao')
                            ? $variacao->estoquesComLocalizacao
                            : collect();

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

                        $obsItem = trim((string)($item->observacoes ?? ''));
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
                        <td class="wrap">{{ $obsItem !== '' ? $obsItem : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endforeach

<div style="margin-top: 10px;"><strong>OBS (GERAL):</strong></div>
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
