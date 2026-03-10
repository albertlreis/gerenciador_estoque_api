<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Catalogo Outlet</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #000; }
        .header { text-align: center; margin-bottom: 10px; }
        .grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grid td { width: 50%; vertical-align: top; padding: 6px; }
        .card { border: 1px solid #ddd; border-radius: 6px; padding: 8px; min-height: 245px; }
        .card-layout { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .card-layout td { vertical-align: top; }
        .card-image-col { width: 155px; padding-right: 8px; }
        .card-info-col { padding-left: 4px; }
        .card-title { font-weight: bold; font-size: 12px; margin: 0 0 4px; line-height: 1.25; }
        .card-ref { color: #666; font-size: 10px; margin-bottom: 6px; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 10px; background: #f3c000; font-size: 9px; font-weight: bold; }
        .badge-set { display: inline-block; padding: 2px 6px; border-radius: 10px; background: #202938; color: #fff; font-size: 9px; font-weight: bold; }
        .img-box { width: 140px; height: 140px; border: 1px solid #ccc; text-align: center; padding: 2px; }
        .img-box img { display: block; margin: 0 auto; width: 140px; max-height: 140px; }
        .img-placeholder { width: 140px; height: 140px; line-height: 140px; text-align: center; color: #888; font-size: 10px; }
        .attrs-title { margin-top: 6px; font-size: 10px; font-weight: bold; color: #222; }
        .attrs-list { margin: 3px 0 0 0; padding-left: 14px; font-size: 9px; color: #333; }
        .attrs-list li { margin: 0 0 2px; }
        .price-new { font-size: 14px; font-weight: bold; margin-top: 4px; }
        .text-muted { color: #666; font-size: 10px; }
        .set-description { font-size: 10px; color: #333; margin: 6px 0; }
        .items-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .items-table th,
        .items-table td { border-top: 1px solid #e5e5e5; padding: 4px 2px; font-size: 9px; text-align: left; vertical-align: top; }
        .items-table th { color: #555; font-weight: bold; }
        .page-break { page-break-after: always; }
        .footer { text-align: center; color: #666; font-size: 9px; margin-top: 6px; }
    </style>
</head>
<body>
<div class="header">
    <img src="{{ public_path('logo.png') }}" width="120" alt="Logo"/>
    <h3>CATALOGO OUTLET</h3>
</div>

@php
    $cardsCollection = collect($cards ?? collect())->values();
@endphp

@foreach($cardsCollection->chunk(4) as $pagina)
    <table class="grid">
        <tbody>
        @foreach($pagina->chunk(2) as $linha)
            <tr>
                @foreach($linha as $card)
                    @php
                        $altura = $card['altura'] ?? null;
                        $largura = $card['largura'] ?? null;
                        $profundidade = $card['profundidade'] ?? null;
                        $temMedidas = $altura !== null || $largura !== null || $profundidade !== null;
                        $medidas = $temMedidas
                            ? 'A ' . ($altura !== null && $altura !== '' ? $altura : '-') .
                                ' x L ' . ($largura !== null && $largura !== '' ? $largura : '-') .
                                ' x P ' . ($profundidade !== null && $profundidade !== '' ? $profundidade : '-') . ' cm'
                            : null;
                        $atributos = collect($card['atributos_acabamentos'] ?? [])->filter()->values();
                        $itensConjunto = collect($card['itens'] ?? []);
                    @endphp
                    <td>
                        <div class="card">
                            <table class="card-layout">
                                <tbody>
                                <tr>
                                    <td class="card-image-col">
                                        <div class="img-box">
                                            @if(!empty($card['imagem_src']))
                                                <img src="{{ $card['imagem_src'] }}" alt="Imagem do produto"/>
                                            @else
                                                <div class="img-placeholder">Sem imagem</div>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="card-info-col">
                                        @if(($card['tipo'] ?? 'avulso') === 'conjunto')
                                            <div class="card-title">{{ $card['nome'] ?? '-' }}</div>
                                            <div class="badge-set">Conjunto</div>

                                            @if(!empty($card['descricao']))
                                                <div class="set-description">{{ $card['descricao'] }}</div>
                                            @else
                                                <div class="set-description text-muted">Descricao nao informada.</div>
                                            @endif

                                            <div class="price-new">{{ $card['preco_label'] ?? '-' }}</div>

                                            <table class="items-table">
                                                <thead>
                                                <tr>
                                                    <th>Item</th>
                                                    <th>Ref.</th>
                                                    <th>Qtd.</th>
                                                    @if(($card['preco_modo'] ?? null) === 'individual')
                                                        <th>Preco</th>
                                                    @endif
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @foreach($itensConjunto as $itemConjunto)
                                                    <tr>
                                                        <td>
                                                            {{ $itemConjunto['label'] ?? '-' }}
                                                            <div class="text-muted">{{ $itemConjunto['nome'] ?? '-' }}</div>
                                                        </td>
                                                        <td>{{ $itemConjunto['referencia'] ?? '-' }}</td>
                                                        <td>{{ $itemConjunto['qtd'] ?? 0 }}</td>
                                                        @if(($card['preco_modo'] ?? null) === 'individual')
                                                            <td>
                                                                @if(isset($itemConjunto['preco']) && $itemConjunto['preco'] !== null)
                                                                    R$ {{ number_format((float) $itemConjunto['preco'], 2, ',', '.') }}
                                                                @else
                                                                    -
                                                                @endif
                                                            </td>
                                                        @endif
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        @else
                                            <div class="card-title">
                                                {{ $card['nome'] ?? '-' }}@if($medidas) - {{ $medidas }}@endif
                                            </div>
                                            <div class="card-ref">Ref.: {{ $card['referencia'] ?? '-' }}</div>
                                            <div class="badge">{{ $card['categoria_nome'] ?? 'Sem categoria' }}</div>
                                            <div class="text-muted" style="margin-top: 4px;">{{ $card['variacao_nome'] ?? '-' }}</div>
                                            <div class="price-new">{{ $card['preco_label'] ?? '-' }}</div>
                                            <div class="text-muted">Disponivel no outlet: {{ $card['qtd_total_restante'] ?? 0 }}</div>

                                            <div class="attrs-title">Atributos / Acabamentos</div>
                                            @if($atributos->isNotEmpty())
                                                <ul class="attrs-list">
                                                    @foreach($atributos->take(6) as $atributoLinha)
                                                        <li>{{ $atributoLinha }}</li>
                                                    @endforeach
                                                </ul>
                                            @else
                                                <div class="card-ref">Nao informado</div>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </td>
                @endforeach

                @if($linha->count() === 1)
                    <td>
                        <div class="card">&nbsp;</div>
                    </td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="footer">Sujeito a disponibilidade</div>

    @if(!$loop->last)
        <div class="page-break"></div>
    @endif
@endforeach
</body>
</html>
