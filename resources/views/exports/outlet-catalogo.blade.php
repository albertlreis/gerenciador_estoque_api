<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Catalogo Outlet</title>
    <style>
        @page { margin: 12px 14px 10px; }
        body {
            margin: 0;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 11px;
            color: #221d18;
        }
        .page {
            width: 100%;
        }
        .header-accent {
            height: 5px;
            background: #b89662;
            margin-bottom: 8px;
        }
        .header-shell {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 7px;
            border-bottom: 1px solid #d7c8b2;
        }
        .header-shell td {
            vertical-align: middle;
            padding-bottom: 7px;
        }
        .header-logo {
            width: 126px;
            padding-right: 13px;
        }
        .header-copy {
            text-align: left;
        }
        .header-kicker {
            font-size: 9.5px;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #8a7353;
            margin-bottom: 3px;
        }
        .header-title {
            font-size: 25px;
            font-weight: bold;
            letter-spacing: 1px;
            margin: 0 0 2px;
            color: #1e1a16;
        }
        .header-subtitle {
            font-size: 11px;
            color: #6d6254;
        }
        .catalog-grid {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .catalog-grid td {
            width: 50%;
            vertical-align: top;
            padding: 5px;
        }
        .card {
            border: 1px solid #ddd2c2;
            border-radius: 12px;
            background: #fffdf9;
            min-height: 304px;
        }
        .card-empty {
            background: #faf7f1;
            border-style: dashed;
        }
        .card-layout {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .card-layout td {
            vertical-align: top;
        }
        .card-media {
            width: 130px;
            padding: 12px 0 12px 12px;
        }
        .card-content {
            padding: 12px 12px 11px 9px;
        }
        .media-frame {
            width: 118px;
            height: 122px;
            border: 1px solid #e3d8c8;
            border-radius: 10px;
            background: #f6f1e8;
            overflow: hidden;
        }
        .media-frame-table {
            width: 100%;
            height: 100%;
            border-collapse: collapse;
        }
        .media-frame-table td {
            vertical-align: middle;
            text-align: center;
            padding: 6px;
        }
        .media-image {
            max-width: 104px;
            max-height: 108px;
        }
        .media-placeholder-icon {
            width: 30px;
            height: 30px;
            margin: 0 auto 7px;
            border: 1px solid #d6cab9;
            border-radius: 8px;
            background: #fbf7f1;
            position: relative;
        }
        .media-placeholder-icon:before {
            content: "";
            position: absolute;
            top: 7px;
            left: 8px;
            width: 12px;
            height: 9px;
            border: 1px solid #b6a48f;
            border-radius: 2px;
            background: #fffdf9;
        }
        .media-placeholder-icon:after {
            content: "";
            position: absolute;
            top: 11px;
            left: 16px;
            width: 3px;
            height: 3px;
            border-radius: 999px;
            background: #b6a48f;
        }
        .media-placeholder-mark {
            display: inline-block;
            padding: 3px 8px;
            border: 1px solid #d6cab9;
            border-radius: 999px;
            font-size: 8px;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #8c7b67;
            background: #fbf8f3;
            margin-bottom: 6px;
        }
        .media-placeholder-text {
            font-size: 9px;
            color: #786c5d;
            line-height: 1.35;
        }
        .meta-row {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
        }
        .meta-row td {
            vertical-align: top;
        }
        .meta-right {
            text-align: right;
        }
        .badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 999px;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            margin-right: 4px;
            margin-bottom: 3px;
        }
        .badge-outlet {
            background: #1f1a17;
            color: #f5ede2;
        }
        .badge-soft {
            background: #f1e7d6;
            color: #6f583b;
            border: 1px solid #dfd1ba;
        }
        .card-title {
            font-size: 17.5px;
            font-weight: bold;
            line-height: 1.16;
            margin: 0 0 3px;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .card-subtitle {
            font-size: 9.5px;
            color: #6d6458;
            line-height: 1.35;
            margin: 0 0 5px;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .card-description {
            font-size: 9.5px;
            color: #50473e;
            line-height: 1.35;
            margin: 0 0 5px;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .specs {
            font-size: 10.5px;
            color: #645848;
            line-height: 1.32;
            margin-bottom: 5px;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .price-panel {
            border: 1px solid #ddcfb8;
            border-radius: 11px;
            background: #f8efe1;
            padding: 11px 12px 10px;
            margin-bottom: 5px;
        }
        .price-label {
            font-size: 8.2px;
            text-transform: uppercase;
            letter-spacing: 1.3px;
            color: #896a3f;
            margin-bottom: 5px;
        }
        .price-current {
            font-size: 25px;
            line-height: 1;
            font-weight: bold;
            color: #1d1a16;
        }
        .price-original {
            margin-top: 3px;
            font-size: 10.2px;
            color: #867a6b;
            text-decoration: line-through;
        }
        .discount-badge {
            display: inline-block;
            margin-top: 4px;
            padding: 2px 6px;
            border-radius: 999px;
            background: #ede0c7;
            color: #765a34;
            font-size: 8px;
            font-weight: bold;
        }
        .availability {
            font-size: 11.5px;
            color: #2c241d;
            margin-bottom: 4px;
        }
        .availability strong {
            color: #6d5432;
        }
        .secondary-note {
            font-size: 9px;
            color: #6e665d;
            line-height: 1.3;
            margin-bottom: 4px;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .chips {
            margin-top: 2px;
        }
        .chip {
            display: inline-block;
            margin: 0 4px 4px 0;
            padding: 2px 7px 3px;
            border: 1px solid #e5dbcd;
            border-radius: 12px;
            background: #faf7f2;
            color: #5e5143;
            font-size: 8px;
            line-height: 1.25;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .reference-line {
            margin-top: 5px;
            font-size: 9.2px;
            color: #7a7065;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        .items-table th,
        .items-table td {
            border-top: 1px solid #ece3d6;
            padding: 4px 3px;
            text-align: left;
            vertical-align: top;
        }
        .items-table th {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #7d6a4f;
            background: #f8f2e8;
        }
        .items-table td {
            font-size: 9px;
            color: #2f2821;
        }
        .item-name {
            font-weight: bold;
            margin-bottom: 1px;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .item-meta {
            font-size: 8px;
            color: #756b61;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .item-price-current {
            font-weight: bold;
            color: #231e19;
        }
        .item-price-original {
            display: block;
            margin-top: 1px;
            font-size: 8px;
            color: #8a7d6e;
            text-decoration: line-through;
        }
        .footer {
            margin-top: 4px;
            padding-top: 4px;
            border-top: 1px solid #ded3c4;
            text-align: center;
            font-size: 9px;
            color: #6d665f;
        }
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
@php
    $cardsCollection = collect($cards ?? collect())->values();
@endphp

@foreach($cardsCollection->chunk(4) as $pagina)
    <div class="page">
        <div class="header-accent"></div>
        <table class="header-shell">
            <tr>
                <td class="header-logo">
                    <img src="{{ public_path('logo.png') }}" width="118" alt="Logo Sierra"/>
                </td>
                <td class="header-copy">
                    <div class="header-kicker">Sierra Collection</div>
                    <div class="header-title">CATALOGO OUTLET</div>
                    <div class="header-subtitle">Pecas selecionadas com condicoes especiais</div>
                </td>
            </tr>
        </table>

        <table class="catalog-grid">
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
                            $atributosVisiveis = $atributos->take(8);
                            $atributosRestantes = max(0, $atributos->count() - $atributosVisiveis->count());
                            $itensConjunto = collect($card['itens'] ?? []);
                            $tipoCard = $card['tipo'] ?? 'avulso';
                        @endphp
                        <td>
                            <div class="card">
                                <table class="card-layout">
                                    <tr>
                                        <td class="card-media">
                                            <div class="media-frame">
                                                <table class="media-frame-table">
                                                    <tr>
                                                        <td>
                                                            @if(!empty($card['imagem_src']))
                                                                <img class="media-image" src="{{ $card['imagem_src'] }}" alt="Imagem do item"/>
                                                            @else
                                                                <div class="media-placeholder-icon"></div>
                                                                <div class="media-placeholder-mark">Outlet Sierra</div>
                                                                <div class="media-placeholder-text">
                                                                    Foto nao disponivel
                                                                </div>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </td>
                                        <td class="card-content">
                                            <table class="meta-row">
                                                <tr>
                                                    <td>
                                                        <span class="badge badge-outlet">Outlet</span>
                                                        <span class="badge badge-soft">
                                                            {{ $tipoCard === 'conjunto' ? 'Conjunto' : ($card['categoria_nome'] ?? 'Selecao Sierra') }}
                                                        </span>
                                                    </td>
                                                    <td class="meta-right">
                                                        @if($tipoCard === 'conjunto')
                                                            <span class="secondary-note">{{ $itensConjunto->count() }} itens selecionados</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            </table>

                                            @if($tipoCard === 'conjunto')
                                                <div class="card-title">{{ $card['nome'] ?? '-' }}</div>

                                                @if(!empty($card['descricao']))
                                                    <div class="card-description">{{ $card['descricao'] }}</div>
                                                @else
                                                    <div class="card-subtitle">Composicao especial com disponibilidade ativa no outlet.</div>
                                                @endif

                                                <div class="price-panel">
                                                    <div class="price-label">Preco especial do conjunto</div>
                                                    <div class="price-current">{{ $card['preco_label'] ?? '-' }}</div>
                                                    @if(!empty($card['preco_original_label']))
                                                        <div class="price-original">{{ $card['preco_original_label'] }}</div>
                                                    @endif
                                                </div>

                                                <div class="availability">
                                                    <strong>Disponibilidade:</strong> itens com saldo ativo para composicao.
                                                </div>

                                                <table class="items-table">
                                                    <thead>
                                                    <tr>
                                                        <th>Item</th>
                                                        <th>Referencia</th>
                                                        <th>Disp.</th>
                                                        @if(($card['preco_modo'] ?? null) === 'individual')
                                                            <th>Preco</th>
                                                        @endif
                                                    </tr>
                                                    </thead>
                                                    <tbody>
                                                    @foreach($itensConjunto as $itemConjunto)
                                                        <tr>
                                                            <td>
                                                                <div class="item-name">{{ $itemConjunto['label'] ?? '-' }}</div>
                                                                <div class="item-meta">{{ $itemConjunto['nome'] ?? '-' }}</div>
                                                            </td>
                                                            <td>{{ $itemConjunto['referencia'] ?? '-' }}</td>
                                                            <td>{{ $itemConjunto['qtd'] ?? 0 }} un.</td>
                                                            @if(($card['preco_modo'] ?? null) === 'individual')
                                                                <td>
                                                                    <span class="item-price-current">{{ $itemConjunto['preco_label'] ?? '-' }}</span>
                                                                    @if(!empty($itemConjunto['preco_original_label']))
                                                                        <span class="item-price-original">{{ $itemConjunto['preco_original_label'] }}</span>
                                                                    @endif
                                                                </td>
                                                            @endif
                                                        </tr>
                                                    @endforeach
                                                    </tbody>
                                                </table>
                                            @else
                                                <div class="card-title">{{ $card['nome'] ?? '-' }}</div>

                                                @if(!empty($card['variacao_nome']) && ($card['variacao_nome'] !== ($card['nome'] ?? null)))
                                                    <div class="card-subtitle">{{ $card['variacao_nome'] }}</div>
                                                @endif

                                                @if($medidas)
                                                    <div class="specs">{{ $medidas }}</div>
                                                @endif

                                                <div class="price-panel">
                                                    <div class="price-label">Preco outlet</div>
                                                    <div class="price-current">{{ $card['preco_label'] ?? '-' }}</div>
                                                    @if(!empty($card['preco_original_label']))
                                                        <div class="price-original">{{ $card['preco_original_label'] }}</div>
                                                    @endif
                                                    @if(!empty($card['percentual_desconto']))
                                                        <div class="discount-badge">Ate {{ number_format((float) $card['percentual_desconto'], 0, ',', '.') }}% de desconto</div>
                                                    @endif
                                                </div>

                                                <div class="availability">
                                                    <strong>Disponibilidade:</strong> {{ $card['qtd_total_restante'] ?? 0 }} un.
                                                </div>

                                                @if(!empty($card['pagamento_label']))
                                                    <div class="secondary-note">Condicao comercial: {{ $card['pagamento_label'] }}</div>
                                                @endif

                                                @if($atributosVisiveis->isNotEmpty())
                                                    <div class="chips">
                                                        @foreach($atributosVisiveis as $atributoLinha)
                                                            <span class="chip">{{ $atributoLinha }}</span>
                                                        @endforeach
                                                        @if($atributosRestantes > 0)
                                                            <span class="chip">+{{ $atributosRestantes }} detalhes</span>
                                                        @endif
                                                    </div>
                                                @else
                                                    <div class="secondary-note">Detalhes de acabamento nao informados.</div>
                                                @endif

                                                <div class="reference-line">Referencia: {{ $card['referencia'] ?? '-' }}</div>
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    @endforeach

                    @if($linha->count() === 1)
                        <td>
                            <div class="card card-empty"></div>
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="footer">
            Valores e disponibilidade sujeitos a confirmacao.
            @if(!empty($generatedAt))
                <span>Gerado em {{ $generatedAt }}.</span>
            @endif
        </div>
    </div>

    @if(!$loop->last)
        <div class="page-break"></div>
    @endif
@endforeach
</body>
</html>
