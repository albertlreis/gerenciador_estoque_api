@php use Carbon\Carbon; @endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Nota de Entrega</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 11px; color: #1f2933; margin: 0; }
        .header { border-bottom: 2px solid #1f2933; padding-bottom: 10px; margin-bottom: 12px; }
        .brand-row { width: 100%; }
        .brand-row td { vertical-align: middle; }
        .title { text-align: right; }
        .title h1 { margin: 0; font-size: 22px; letter-spacing: .5px; text-transform: uppercase; }
        .title span { color: #5b6470; font-size: 11px; }
        .box { border: 1px solid #cfd6dd; margin-bottom: 10px; }
        .box-title { background: #f4f6f8; color: #1f2933; font-weight: bold; padding: 6px 8px; text-transform: uppercase; }
        .box-content { padding: 8px; }
        .info-table { width: 100%; border-collapse: collapse; }
        .info-table td { padding: 3px 4px; vertical-align: top; }
        .label { color: #5b6470; font-weight: bold; white-space: nowrap; }
        .items { width: 100%; border-collapse: collapse; }
        .items th { background: #1f2933; color: #fff; font-size: 10px; padding: 6px 5px; text-align: left; }
        .items td { border: 1px solid #d8dee4; padding: 5px; vertical-align: top; }
        .items img { display: block; margin: auto; border: 1px solid #d8dee4; }
        .qty { text-align: center; font-size: 14px; font-weight: bold; white-space: nowrap; }
        .product-name { font-weight: bold; margin-bottom: 3px; }
        .muted { color: #667085; }
        .wrap { word-break: break-word; overflow-wrap: anywhere; white-space: normal; }
        .obs { min-height: 44px; border: 1px solid #cfd6dd; padding: 7px; margin-bottom: 12px; }
        .receipt { border: 1px solid #1f2933; padding: 10px; margin-top: 14px; }
        .receipt-text { text-align: center; font-weight: bold; margin-bottom: 18px; }
        .signature-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .signature-table td { width: 33.33%; padding: 0 8px; text-align: center; }
        .signature-line { border-top: 1px solid #1f2933; padding-top: 5px; min-height: 24px; }
        .footer { text-align: center; color: #667085; font-size: 10px; margin-top: 12px; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
    </style>
</head>
<body>
@php
    $enderecoPrincipal = $enderecoEntrega ?? $pedido->cliente?->enderecoPrincipal ?? null;
    $enderecoTexto = '-';

    if ($enderecoPrincipal) {
        $cidade = trim((string)($enderecoPrincipal->cidade ?? ''));
        $estado = trim((string)($enderecoPrincipal->estado ?? ''));
        $cidadeEstado = trim($cidade . ($cidade !== '' && $estado !== '' ? '/' : '') . $estado);
        $cep = trim((string)($enderecoPrincipal->cep ?? ''));

        $enderecoTexto = trim(implode(' - ', array_filter([
            $enderecoPrincipal->endereco ?? null,
            $enderecoPrincipal->numero ?? null,
            $enderecoPrincipal->complemento ?? null,
            $enderecoPrincipal->bairro ?? null,
            $cidadeEstado !== '' ? $cidadeEstado : null,
            $cep !== '' ? 'CEP ' . $cep : null,
        ], fn($valor) => trim((string)$valor) !== '')));
    } elseif (!empty($pedido->cliente?->endereco)) {
        $enderecoTexto = $pedido->cliente->endereco;
    }

    $logoPath = public_path('logo.png');
    $logoSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="150" height="34" viewBox="0 0 150 34"><rect width="150" height="34" rx="4" fill="#1f1a17"/><text x="75" y="22" text-anchor="middle" font-family="Arial, sans-serif" font-size="16" fill="#f5ede2">Sierra</text></svg>';
    $logoSrc = is_file($logoPath) ? $logoPath : 'data:image/svg+xml;base64,' . base64_encode($logoSvg);
@endphp

<div class="header">
    <table class="brand-row">
        <tr>
            <td>
                <img src="{{ $logoSrc }}" width="130" alt="Sierra">
            </td>
            <td class="title">
                <h1>Nota de Entrega</h1>
                <span>Documento operacional de recebimento pelo cliente</span>
            </td>
        </tr>
    </table>
</div>

<div class="box">
    <div class="box-title">Pedido e cliente</div>
    <div class="box-content">
        <table class="info-table">
            <tr>
                <td><span class="label">Pedido:</span> {{ $pedido->numero_externo ?? $pedido->id }}</td>
                <td><span class="label">Emissao:</span> {{ $geradoEm ?? '-' }}</td>
            </tr>
            <tr>
                <td><span class="label">Data do pedido:</span> {{ $pedido->data_pedido ? Carbon::parse($pedido->data_pedido)->format('d/m/Y') : '-' }}</td>
                <td><span class="label">Vendedor(a):</span> {{ $pedido->usuario->nome ?? '-' }}</td>
            </tr>
            <tr>
                <td class="wrap"><span class="label">Cliente:</span> {{ $pedido->cliente->nome ?? '-' }}</td>
                <td><span class="label">Documento:</span> {{ $pedido->cliente->documento ?? '-' }}</td>
            </tr>
            <tr>
                <td><span class="label">Telefone:</span> {{ $pedido->cliente->telefone ?? '-' }}</td>
                <td><span class="label">Parceiro:</span> {{ $pedido->parceiro->nome ?? '-' }}</td>
            </tr>
            <tr>
                <td colspan="2" class="wrap"><span class="label">Endereco:</span> {{ $enderecoTexto ?: '-' }}</td>
            </tr>
        </table>
    </div>
</div>

<div class="box">
    <div class="box-title">Produtos entregues</div>
    <div class="box-content">
        <table class="items">
            <thead>
            <tr>
                <th style="width: 86px; text-align: center;">Imagem</th>
                <th style="width: 54px; text-align: center;">Qtd</th>
                <th>Produto</th>
                <th style="width: 130px;">Referencia</th>
                <th style="width: 150px;">Observacao</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($itens as $item)
                @php
                    $variacao = $item->variacao;
                    $produtoNome = trim((string)($variacao?->produto?->nome ?? ''));
                    $variacaoNome = trim((string)($variacao?->nome_completo ?? $variacao?->nome ?? ''));
                    $atributos = $variacao && $variacao->relationLoaded('atributos')
                        ? $variacao->atributos->pluck('valor')->filter()->implode(', ')
                        : '';
                    $referencia = trim((string)($variacao?->referencia ?? ''));
                    $descricao = $produtoNome !== '' ? $produtoNome : ($variacaoNome !== '' ? $variacaoNome : 'Produto');
                    $detalhe = trim(implode(' | ', array_filter([$variacaoNome, $atributos])));
                    $imgDataUri = trim((string)($item->pdf_imagem_data_uri ?? ''))
                        ?: app(\App\Services\PdfImageService::class)->placeholderDataUri();
                    $obsItem = trim((string)($item->pedidoItem?->observacoes ?? ''));
                @endphp
                <tr>
                    <td style="text-align: center;">
                        <img src="{{ $imgDataUri }}" width="74" height="62" style="object-fit:cover;" alt="Imagem do produto">
                    </td>
                    <td class="qty">{{ (int)($item->nota_quantidade ?? 0) }}</td>
                    <td class="wrap">
                        <div class="product-name">{{ $descricao }}</div>
                        @if($detalhe !== '')
                            <div class="muted">{{ $detalhe }}</div>
                        @endif
                    </td>
                    <td class="wrap">{{ $referencia !== '' ? $referencia : '-' }}</td>
                    <td class="wrap">{{ $obsItem !== '' ? $obsItem : '-' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div><strong>Observacoes gerais</strong></div>
<div class="obs">
    {{ trim((string)($observacaoNota ?? '')) !== '' ? $observacaoNota : ($pedido->observacoes ?? '') }}
</div>

<div class="receipt">
    <div class="receipt-text">
        Recebi os produtos listados acima em perfeito estado no ato da entrega.
    </div>
    <table class="signature-table">
        <tr>
            <td><div class="signature-line">Nome do recebedor</div></td>
            <td><div class="signature-line">Documento</div></td>
            <td><div class="signature-line">Data</div></td>
        </tr>
        <tr>
            <td colspan="3" style="padding-top: 28px;">
                <div class="signature-line">Assinatura</div>
            </td>
        </tr>
    </table>
</div>

<div class="footer">
    Nota de entrega vinculada ao pedido {{ $pedido->numero_externo ?? $pedido->id }}.
</div>
</body>
</html>
