<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Catálogo Outlet</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #000; }
        .header { text-align: center; margin-bottom: 10px; }
        .grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grid td { width: 50%; vertical-align: top; padding: 6px; }
        .card { border: 1px solid #ddd; border-radius: 6px; padding: 8px; }
        .card-title { font-weight: bold; font-size: 12px; margin: 6px 0 2px; }
        .card-ref { color: #666; font-size: 10px; margin-bottom: 6px; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 10px; background: #f3c000; font-size: 9px; font-weight: bold; }
        .badge-discount { display: inline-block; padding: 2px 6px; border-radius: 10px; background: #ffe7b3; font-size: 9px; color: #7a4b00; }
        .img-box { width: 140px; height: 140px; border: 1px solid #ccc; text-align: center; padding: 2px; }
        .img-box img { display: block; margin: 0 auto; width: 140px; max-height: 140px; }
        .img-placeholder { width: 140px; height: 140px; line-height: 140px; text-align: center; color: #888; font-size: 10px; }
        .price-old { color: #888; text-decoration: line-through; font-size: 10px; margin-top: 6px; }
        .price-new { font-size: 14px; font-weight: bold; margin-top: 4px; }
        .page-break { page-break-after: always; }
        .footer { text-align: center; color: #666; font-size: 9px; margin-top: 6px; }
    </style>
</head>
<body>
<div class="header">
    <img src="{{ public_path('logo.png') }}" width="120" alt="Logo"/>
    <h3>CATÁLOGO OUTLET</h3>
</div>

@foreach($produtos->chunk(6) as $pagina)
    <table class="grid">
        <tbody>
        @foreach($pagina->chunk(2) as $linha)
            <tr>
                @foreach($linha as $produto)
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
                    <td>
                        <div class="card">
                            <div class="img-box">
                                @if($imgAbs)
                                    <img src="{{ $imgAbs }}" alt="Imagem do produto"/>
                                @else
                                    <div class="img-placeholder">Sem imagem</div>
                                @endif
                            </div>

                            <div class="card-title">{{ $produto->nome ?? '—' }}</div>
                            <div class="card-ref">Ref.: {{ $refs ?: '—' }}</div>

                            <div class="badge">{{ $produto->categoria?->nome ?? 'Sem categoria' }}</div>
                            @if($descontoMax !== null)
                                <div style="margin-top: 4px;">
                                    <span class="badge-discount">Desconto: {{ number_format($descontoMax, 2, ',', '.') }}%</span>
                                </div>
                            @endif

                            @if($precoMin !== null)
                                <div class="price-old">R$ {{ number_format($precoMin, 2, ',', '.') }}</div>
                            @endif

                            <div class="price-new">
                                @if($precoOutlet !== null)
                                    R$ {{ number_format($precoOutlet, 2, ',', '.') }}
                                @else
                                    Preço outlet indisponível
                                @endif
                            </div>
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

    <div class="footer">Sujeito à disponibilidade</div>

    @if(!$loop->last)
        <div class="page-break"></div>
    @endif
@endforeach
</body>
</html>
