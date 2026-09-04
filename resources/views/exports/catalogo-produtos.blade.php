<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Catálogo de Produtos</title>
    <style>
        @page { margin: 12px 14px 14px; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "DejaVu Sans", sans-serif; color: #221d18; font-size: 10px; }
        .page { width: 100%; }
        .page-break { page-break-after: always; }
        .accent { height: 5px; margin-bottom: 8px; background: #b89662; }
        .header { width: 100%; border-collapse: collapse; border-bottom: 1px solid #d7c8b2; margin-bottom: 7px; }
        .header td { padding-bottom: 7px; vertical-align: middle; }
        .logo { width: 132px; }
        .kicker { color: #8a7353; font-size: 9px; letter-spacing: 2px; text-transform: uppercase; }
        .title { margin-top: 2px; color: #1e1a16; font-size: 24px; font-weight: bold; letter-spacing: 1px; }
        .subtitle { color: #6d6254; font-size: 10px; }
        .grid { width: 100%; margin-bottom: 2px; border-collapse: collapse; table-layout: fixed; }
        .grid tr { page-break-inside: avoid; }
        .grid > tbody > tr > td { padding: 4px; vertical-align: top; }
        .grid > tbody > tr.category-row > td { padding: 0 4px 2px; }
        .category-band { padding: 4px 8px; border-radius: 7px; background: #1f1a17; color: #f5ede2; font-size: 9px; font-weight: bold; letter-spacing: 1.1px; text-transform: uppercase; }
        .card { min-height: 244px; padding: 7px; border: 1px solid #ddd2c2; border-radius: 10px; background: #fffdf9; overflow: hidden; }
        .content { padding-top: 4px; }
        .image-frame { width: 164px; height: 88px; margin: 0 auto; border: 1px solid #e3d8c8; border-radius: 8px; background: #f6f1e8; overflow: hidden; text-align: center; }
        .image-frame img { display: block; width: 164px; height: 88px; margin: 0; }
        .badges { height: 18px; margin-bottom: 2px; overflow: hidden; }
        .badge { display: inline-block; margin: 0 4px 3px 0; padding: 2px 7px; border-radius: 999px; background: #f1e7d6; color: #6f583b; border: 1px solid #dfd1ba; font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: .6px; }
        .badge.outlet { background: #1f1a17; color: #f5ede2; border-color: #1f1a17; }
        .badge.available { background: #e5f4e8; color: #28623a; border-color: #bdddc5; }
        .badge.unavailable { background: #f8e7e5; color: #8a3a32; border-color: #e6c0bc; }
        .name-wrap { height: 50px; overflow: hidden; }
        .name { margin: 0; font-size: 14px; line-height: 1.12; font-weight: bold; overflow-wrap: anywhere; }
        .name.long { font-size: 12px; line-height: 1.08; }
        .name.very-long { font-size: 9.5px; line-height: 1.04; }
        .meta { height: 24px; overflow: hidden; }
        .reference { margin-bottom: 2px; color: #6d6458; font-size: 8.5px; overflow-wrap: anywhere; }
        .dimensions { color: #645848; font-size: 8.5px; }
        .price { min-height: 36px; margin-bottom: 3px; padding: 4px 7px; border: 1px solid #ddcfb8; border-radius: 8px; background: #f8efe1; }
        .price-label { color: #896a3f; font-size: 8px; letter-spacing: 1px; text-transform: uppercase; }
        .price-current { margin-top: 2px; color: #1d1a16; font-size: 14px; line-height: 1.04; font-weight: bold; }
        .price-current.consultation { font-size: 13px; }
        .price-original { margin-top: 2px; color: #867a6b; text-decoration: line-through; }
        .condition { margin-top: 4px; color: #66543c; font-size: 8.5px; }
        .chips { line-height: 1.45; max-height: 42px; overflow: hidden; }
        .chip { display: inline-block; margin: 0 3px 3px 0; padding: 2px 5px; border-radius: 7px; background: #f3eee6; color: #5f5549; font-size: 8px; }
        .footer { margin-top: 3px; padding-top: 4px; border-top: 1px solid #ded3c4; color: #6d665f; font-size: 8px; text-align: center; }
    </style>
</head>
<body>
@php
    $sortKey = static fn ($value) => \Illuminate\Support\Str::lower(
        \Illuminate\Support\Str::ascii(trim((string) $value))
    );
    $collection = collect($cards ?? [])
        ->map(function (array $card) {
            $card['categoria_nome'] = trim((string) ($card['categoria_nome'] ?? '')) ?: 'Sem categoria';

            return $card;
        })
        ->sortBy(fn (array $card) => implode('|', [
            $sortKey($card['categoria_nome']),
            $sortKey($card['nome'] ?? ''),
            $sortKey($card['referencia'] ?? ''),
        ]))
        ->values();
    $rows = $collection
        ->groupBy('categoria_nome')
        ->flatMap(fn ($categoryCards, $categoryName) => $categoryCards
            ->chunk(4)
            ->map(fn ($rowCards) => [
                'categoria_nome' => $categoryName,
                'cards' => $rowCards->values(),
            ]))
        ->values();
    $pages = $rows->chunk(2);
    $totalPages = max(1, $pages->count());
@endphp

@foreach($pages as $pageRows)
    <div class="page">
        <div class="accent"></div>
        <table class="header">
            <tr>
                <td class="logo"><img src="{{ !extension_loaded('gd') ? 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="118" height="26" viewBox="0 0 118 26"><rect width="118" height="26" rx="4" fill="#1f1a17"/><text x="59" y="17" text-anchor="middle" font-family="Arial, sans-serif" font-size="14" fill="#f5ede2">Sierra</text></svg>') : public_path('logo.png') }}" width="118" alt="Sierra"></td>
                <td>
                    <div class="kicker">Sierra Collection</div>
                    <div class="title">CATÁLOGO DE PRODUTOS</div>
                    <div class="subtitle">Seleção comercial Sierra</div>
                </td>
            </tr>
        </table>
        @php
            $previousCategory = null;
        @endphp
        @foreach($pageRows as $row)
            @php
                $rowCards = $row['cards'];
                $rowCardCount = $rowCards->count();
                $gridWidth = max(25, $rowCardCount * 25);
                $cellWidth = 100 / max(1, $rowCardCount);
                $showCategoryBand = $loop->first || $previousCategory !== $row['categoria_nome'];
                $previousCategory = $row['categoria_nome'];
            @endphp
            <table class="grid" style="width: {{ $gridWidth }}%; margin-left: auto; margin-right: auto;">
                <tbody>
                    @if($showCategoryBand)
                        <tr class="category-row">
                            <td colspan="{{ $rowCardCount }}">
                                <div class="category-band">{{ $row['categoria_nome'] }}</div>
                            </td>
                        </tr>
                    @endif
                    <tr class="product-row" data-category="{{ $row['categoria_nome'] }}">
                        @foreach($rowCards as $card)
                        @php
                            $hasDimensions = $card['altura'] !== null || $card['largura'] !== null || $card['profundidade'] !== null;
                            $dimensions = $hasDimensions
                                ? 'A ' . ($card['altura'] ?? '-') . ' × L ' . ($card['largura'] ?? '-') . ' × P ' . ($card['profundidade'] ?? '-') . ' cm'
                                : null;
                            $attributes = collect($card['atributos'] ?? [])->take(8);
                            $nameLength = mb_strlen((string) $card['nome']);
                            $nameClass = $nameLength > 85 ? 'very-long' : ($nameLength > 55 ? 'long' : '');
                        @endphp
                        <td style="width: {{ $cellWidth }}%;">
                            <div class="card">
                                <div class="image-frame">
                                    <img src="{{ $card['imagem_src'] }}" alt="Imagem do produto">
                                </div>
                                <div class="content">
                                            <div class="badges">
                                                @if($card['is_outlet'])<span class="badge outlet">Outlet</span>@endif
                                                <span class="badge {{ $card['disponivel'] ? 'available' : 'unavailable' }}">
                                                    {{ $card['disponivel'] ? 'Disponível' : 'Indisponível' }}
                                                </span>
                                                @if(!empty($card['categoria_nome']))<span class="badge">{{ $card['categoria_nome'] }}</span>@endif
                                            </div>
                                            <div class="name-wrap">
                                                <div class="name {{ $nameClass }}">{{ $card['nome'] }}</div>
                                            </div>
                                            <div class="meta">
                                                <div class="reference">Referência: {{ $card['referencia'] }}</div>
                                                @if($dimensions)<div class="dimensions">{{ $dimensions }}</div>@endif
                                            </div>

                                            <div class="price">
                                                <div class="price-label">{{ $card['is_outlet'] ? 'Preço outlet' : 'Preço' }}</div>
                                                <div class="price-current {{ !empty($card['preco_sob_consulta']) ? 'consultation' : '' }}">{{ $card['preco_label'] }}</div>
                                                @if(!empty($card['preco_original_label']))
                                                    <div class="price-original">{{ $card['preco_original_label'] }}</div>
                                                @endif
                                                @if(!empty($card['pagamento_label']))
                                                    <div class="condition">Condição: {{ $card['pagamento_label'] }}</div>
                                                @endif
                                            </div>

                                            @if($attributes->isNotEmpty())
                                                <div class="chips">
                                                    @foreach($attributes as $attribute)<span class="chip">{{ $attribute }}</span>@endforeach
                                                </div>
                                            @endif
                                </div>
                            </div>
                        </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        @endforeach

        <div class="footer">
            Gerado em {{ $generatedAt ?? '-' }} · Página {{ $loop->iteration }} de {{ $totalPages }}
        </div>
    </div>
    @if(!$loop->last)<div class="page-break"></div>@endif
@endforeach
</body>
</html>
