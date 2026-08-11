@php use Carbon\Carbon; @endphp
    <!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>{{ $tituloRoteiro ?? 'Roteiro de consignação' }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; color: #000; }
        .header { text-align: center; margin-bottom: 10px; }
        .section { border: 1px solid #000; margin-bottom: 8px; }
        .section-title { background-color: #f3c000; font-weight: bold; padding: 4px; text-transform: uppercase; }
        .section-content table { width: 100%; border-collapse: collapse; }
        .section-content th, .section-content td { border: 1px solid #ccc; padding: 4px; font-size: 10px; vertical-align: top; }
        .observations-block, .closing-block { page-break-inside: avoid; }
        .observations-block { margin-top: 10px; }
        .observations-title { margin-bottom: 2px; }
        .obs { border: 1px solid #000; padding: 5px; min-height: 32px; }
        .closing-block { margin-top: 6px; }
        .recebido {
            border: 1px solid #000;
            font-weight: bold;
            text-align: center;
            color: red;
            padding: 12px 10px 8px;
        }
        .recebido-mensagem { margin-bottom: 16px; }
        .footer { text-align: center; margin-top: 4px; line-height: 1.2; }
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
    <img src="{{ public_path('logo.png') }}" width="120" alt="Logo"/>
    <h3>{{ mb_strtoupper($tituloRoteiro ?? 'Roteiro de consignação') }}</h3>
</div>

@php
    $enderecoSelecionado = $enderecoEntrega ?? $pedido->cliente?->enderecoPrincipal ?? null;
    $enderecoTexto = \App\Support\Pdf\ClienteEnderecoPdf::textoEndereco($enderecoSelecionado);

    if ($enderecoTexto === '' && !empty($pedido->cliente?->endereco)) {
        $enderecoTexto = $pedido->cliente->endereco;
    }
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

                        // Dompdf não consegue resolver com segurança os caminhos legados do
                        // storage. O controller já prepara uma data URI (ou placeholder).
                        $imgDataUri = trim((string)($item->pdf_imagem_data_uri ?? ''))
                            ?: app(\App\Services\PdfImageService::class)->placeholderDataUri();
                        $quantidadeRoteiro = (int)($item->quantidade_roteiro ?? $item->quantidade ?? 0);

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
                            <img src="{{ $imgDataUri }}" width="80" height="64" style="object-fit:cover;" alt="Imagem produto"/>
                        </td>
                        <td class="nowrap">{{ $quantidadeRoteiro }}</td>
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

<div class="observations-block">
    <div class="observations-title"><strong>OBS:</strong></div>
    <div class="obs">{{ $pedido->observacoes ?? '' }}</div>
</div>

<div class="closing-block">
    <div class="recebido">
        <div class="recebido-mensagem">RECEBIDO EM PERFEITO ESTADO NO ATO DA ENTREGA.</div>
        <div class="assinatura">ASS: ________________________________________</div>
    </div>

    <div class="footer">
        Clemente Salheb / Joseane Cunha<br>
        <strong>Sierra Belém</strong>
    </div>
</div>
</body>
</html>
