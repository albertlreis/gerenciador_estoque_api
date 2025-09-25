@php use Carbon\Carbon; @endphp
    <!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Roteiro de Consignação</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; color: #000; }
        .header, .footer { text-align: center; margin-bottom: 10px; }
        .section { border: 1px solid #000; margin-bottom: 8px; }
        .section-title { background-color: #f3c000; font-weight: bold; padding: 4px; text-transform: uppercase; }
        .section-content table { width: 100%; border-collapse: collapse; }
        .section-content th, .section-content td { border: 1px solid #ccc; padding: 4px; font-size: 10px; vertical-align: top; }
        .obs { border: 1px solid #000; padding: 5px; height: 50px; }
        .assinatura { margin-top: 20px; border-top: 1px solid #000; padding-top: 4px; text-align: center; }
        .recebido {
            margin-top: 10px;
            border: 1px solid #000;
            font-weight: bold;
            text-align: center;
            color: red;
            min-height: 110px;
            display: flex;
            justify-content: center;   /* centraliza horizontalmente */
            align-items: flex-end;     /* joga o conteúdo para a parte de baixo */
            padding-bottom: 10px;      /* respiro do texto em relação à borda inferior */
        }
        .section-content img { display: block; margin: auto; border: 1px solid #ccc; }
        .muted { color: #666; }
        .nowrap { white-space: nowrap; }
        .wrap { word-break: break-word; overflow-wrap: anywhere; white-space: normal; }
        /* Evitar quebra no meio da linha no DomPDF */
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
    </style>
</head>
<body>
<div class="header">
    <img src="{{ public_path('logo.png') }}" width="120" alt="Logo"/>
    <h3>ROTEIRO DE CONSIGNAÇÃO</h3>
</div>

<table width="100%" style="margin-bottom: 10px;">
    <tr>
        <td><strong>DATA:</strong> {{ $geradoEm ?? Carbon::now()->format('d/m/Y H:i') }}</td>
        <td><strong>VENDEDOR(A):</strong> {{ $pedido->usuario->nome ?? '-' }}</td>
    </tr>
    <tr>
        <td><strong>CLIENTE:</strong> {{ $pedido->cliente->nome ?? '-' }}</td>
        <td><strong>END:</strong> {{ $pedido->cliente->endereco ?? '-' }}</td>
    </tr>
    <tr>
        <td><strong>TEL:</strong> {{ $pedido->cliente->telefone ?? '-' }}</td>
        <td><strong>PARCEIRO:</strong> {{ $pedido->parceiro->nome ?? '—' }}</td> {{-- <-- parceiro --}}
    </tr>
</table>

@foreach ($grupos as $deposito => $itens)
    <div class="section">
        <div class="section-title">{{ strtoupper($deposito) }}</div>
        <div class="section-content">
            <table>
                <thead>
                <tr>
                    <th>IMG</th>
                    <th>QTD</th>
                    <th>REF</th>
                    <th>DESCRIÇÃO</th>
                    <th>ACAB.</th>
                    <th>LOCALIZAÇÃO</th> {{-- <-- localização --}}
                    <th>DATA ENVIO</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($itens as $item)
                    @php
                        $variacao   = $item->produtoVariacao;
                        $produto    = $variacao->produto;
                        $referencia = $variacao->referencia;
                        $acabamento = $variacao->atributos->pluck('valor')->implode(' / ');
                        $descricao  = $variacao->nome_completo;
                        $dataEnvio  = $item->data_envio ? Carbon::parse($item->data_envio)->format('d/m/Y') : '-';

                        // IMAGEM principal (mesma ideia do relatório de estoque):
                        $imgRel = optional($produto->imagemPrincipal)->url ?? '';
                        $imgAbs = ($imgRel && !empty($baseFsDir ?? null))
                            ? ($baseFsDir . DIRECTORY_SEPARATOR . $imgRel)
                            : '';

                        // LOCALIZAÇÃO: pegar o estoque da variação correspondente ao depósito da consignação
                        $locTexto = '—';
                        $estoques = $variacao->relationLoaded('estoquesComLocalizacao')
                            ? $variacao->estoquesComLocalizacao
                            : collect();

                        $estoqueDoDeposito = $estoques->first(fn($e) => (int)($e->deposito_id ?? 0) === (int)($item->deposito_id ?? 0));
                        if ($estoqueDoDeposito && $estoqueDoDeposito->localizacao) {
                            $loc = $estoqueDoDeposito->localizacao;
                            if (!empty($loc->codigo_composto)) {
                                $locTexto = $loc->codigo_composto;
                            } else {
                                $partes = [];
                                if ($loc->setor)  $partes[] = 'Setor: ' . $loc->setor;
                                if ($loc->coluna) $partes[] = 'Coluna: ' . $loc->coluna;
                                if ($loc->nivel)  $partes[] = 'Nível: ' . $loc->nivel;
                                if ($loc->area && $loc->area->nome) $partes[] = 'Área: ' . $loc->area->nome;
                                $locTexto = $partes ? implode(' | ', $partes) : '—';
                            }
                        }
                    @endphp
                    <tr>
                        <td style="text-align:center; width: 84px;">
                            @if($imgAbs)
                                <img src="{{ $imgAbs }}" width="80" style="max-height:64px;" alt="Imagem produto"/>
                            @endif
                        </td>
                        <td class="nowrap">{{ $item->quantidade }}</td>
                        <td class="nowrap">{{ $referencia ?? '-' }}</td>
                        <td class="wrap">{{ $descricao }}</td>
                        <td class="wrap">{{ $acabamento }}</td>
                        <td class="wrap">{{ $locTexto }}</td> {{-- localização exibida --}}
                        <td class="nowrap">{{ $dataEnvio }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endforeach

<div style="margin-top: 10px;"><strong>OBS:</strong></div>
<div class="obs"></div>

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
