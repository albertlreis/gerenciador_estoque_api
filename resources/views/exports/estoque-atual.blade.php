@extends('layouts.pdf')

@section('titulo', 'Relatório de Estoque Atual')

@section('conteudo')
    @php
        /** @var array<string, array> $dados */
        $somenteOutlet = request()->boolean('somente_outlet');
        $compacto = $compacto ?? false;

        $baseFsDir = isset($baseFsDir) && is_dir($baseFsDir)
            ? $baseFsDir
            : public_path('storage/produtos');

        $totaisDeposito = [];
        $totalGeralQuantidade = 0;
        $totalGeralValor = 0.0;

        foreach ($dados as $produtoId => $info) {
            $totalGeralQuantidade += (int) ($info['estoque_total'] ?? 0);
            $totalGeralValor     += (float) ($info['valor_total'] ?? 0);

            if (!$compacto) {
                foreach (($info['estoque_por_deposito'] ?? []) as $dep) {
                    $depId  = $dep['id'] ?? null;
                    $depNome = $dep['nome'] ?? '—';
                    if (!isset($totaisDeposito[$depId])) {
                        $totaisDeposito[$depId] = ['nome' => $depNome, 'quantidade' => 0, 'valor' => 0.0];
                    }
                    $totaisDeposito[$depId]['quantidade'] += (int) ($dep['quantidade'] ?? 0);
                    $totaisDeposito[$depId]['valor']      += (float) ($dep['valor'] ?? 0);
                }
            }
        }
        if (!$compacto) {
            uasort($totaisDeposito, fn($a, $b) => strcmp($a['nome'], $b['nome']));
        }

        // Larguras desejadas (em px) para colunas “estreitas”
        $wImg   = 80;   // quando exibir imagem
        $wCat   = 90;
        $wQtd   = 60;   // <- mais estreita
        $wVal   = 78;   // <- mais estreita
        // “Por Depósito” fica percentual para sobrar espaço ao Produto
        $wDepPc = 25;   // %
    @endphp

    <style>
        * { box-sizing: border-box; }

        /* Tabela com layout estável; colgroup dita as larguras. */
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead th { border-bottom: 1px solid #999; font-weight: bold; font-size: 12px; padding: 6px; }
        tbody td { border-bottom: 1px solid #ddd; font-size: 11px; padding: 6px; vertical-align: top; }

        /* Cabeçalho repete a cada página e evita cortar linhas. */
        thead { display: table-header-group; }
        tfoot { display: table-row-group; }
        tr { page-break-inside: avoid; }

        .text-right { text-align: right; }
        .nowrap { white-space: nowrap; }
        .muted { color: #666; }

        /* 🌟 Quebra segura para nomes grandes */
        .wrap {
            word-break: break-word;
            overflow-wrap: anywhere;
            white-space: normal;
        }
    </style>

    <table cellspacing="0" cellpadding="0">
        {{-- COLGROUP garante as larguras das colunas em DomPDF --}}
        <colgroup>
            @if($somenteOutlet && !$compacto)
                <col width="{{ $wImg }}">
            @endif
            {{-- Produto pega o espaço restante depois das demais colunas --}}
            <col>
            <col width="{{ $wCat }}">
            <col width="{{ $wQtd }}">
            <col width="{{ $wVal }}">
            @if(!$compacto)
                <col style="width: {{ $wDepPc }}%;">
            @endif
        </colgroup>

        <thead>
        <tr>
            @if($somenteOutlet && !$compacto)
                <th>Imagem</th>
            @endif
            <th>Produto</th>
            <th>Categoria</th>
            <th>Quantidade</th>
            <th>Valor (R$)</th>
            @if(!$compacto)
                <th>Por Depósito</th>
            @endif
        </tr>
        </thead>

        <tbody>
        @foreach($dados as $produtoId => $info)
            <tr>
                @if($somenteOutlet && !$compacto)
                    <td style="text-align:center;">
                        @php
                            $imgRel = trim((string)($info['imagem_principal'] ?? ''));
                            $imgAbs = $imgRel !== '' ? $baseFsDir . DIRECTORY_SEPARATOR . $imgRel : '';
                        @endphp
                        @if($imgAbs)
                            <img src="{{ $imgAbs }}" alt="Imagem" style="max-height:64px; max-width:{{ $wImg - 4 }}px;">
                        @endif
                    </td>
                @endif

                <td class="wrap">{{ $info['produto'] ?? '—' }}</td>
                <td class="nowrap">{{ $info['categoria'] ?? '—' }}</td>
                <td class="text-right">{{ number_format((int)($info['estoque_total'] ?? 0), 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format((float)($info['valor_total'] ?? 0), 2, ',', '.') }}</td>

                @if(!$compacto)
                    <td>
                        @forelse(($info['estoque_por_deposito'] ?? []) as $dep)
                            {{ $dep['nome'] ?? '—' }}:
                            {{ number_format((int)($dep['quantidade'] ?? 0), 0, ',', '.') }}
                            — {{ number_format((float)($dep['valor'] ?? 0), 2, ',', '.') }}<br>
                        @empty
                            <span class="muted">—</span>
                        @endforelse
                    </td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table>

    <div style="height: 8px;"></div>

    @if(!$compacto)
        <h3 style="margin: 8px 0 4px;">Totais por Depósito</h3>
        @if(empty($totaisDeposito))
            <div class="muted">Nenhum depósito encontrado para os filtros selecionados.</div>
        @else
            <table cellspacing="0" cellpadding="0">
                <thead>
                <tr>
                    <th>Depósito</th>
                    <th class="nowrap">Quantidade</th>
                    <th class="nowrap">Valor</th>
                </tr>
                </thead>
                <tbody>
                @foreach($totaisDeposito as $dep)
                    <tr>
                        <td class="nowrap">{{ $dep['nome'] }}</td>
                        <td class="text-right">{{ number_format((int)$dep['quantidade'], 0, ',', '.') }}</td>
                        <td class="text-right">R$ {{ number_format((float)$dep['valor'], 2, ',', '.') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endif

    <div style="height: 8px;"></div>

    <h3 style="margin: 8px 0 4px;">Total Geral</h3>
    <table cellspacing="0" cellpadding="0">
        <thead>
        <tr>
            <th class="nowrap">Quantidade</th>
            <th class="nowrap">Valor</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td class="text-right">{{ number_format((int)$totalGeralQuantidade, 0, ',', '.') }}</td>
            <td class="text-right">R$ {{ number_format((float)$totalGeralValor, 2, ',', '.') }}</td>
        </tr>
        </tbody>
    </table>
@endsection
