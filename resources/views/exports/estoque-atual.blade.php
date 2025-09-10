@extends('layouts.pdf')

@section('titulo', 'Relatório de Estoque Atual')

@section('conteudo')
    @php
        /** @var array<string, array> $dados */
        $somenteOutlet = request()->boolean('somente_outlet');

        // Recebido do controller; se vier vazio, podemos inferir um padrão.
        $baseFsDir = $baseFsDir ?? public_path(env('PRODUCT_IMAGES_FOLDER', 'produtos'));

        $totaisDeposito = [];
        $totalGeralQuantidade = 0;
        $totalGeralValor = 0.0;

        foreach ($dados as $produto => $info) {
            $totalGeralQuantidade += (int) ($info['estoque_total'] ?? 0);
            $totalGeralValor     += (float) ($info['valor_total'] ?? 0);

            foreach (($info['estoque_por_deposito'] ?? []) as $dep) {
                $depId = $dep['id'] ?? null;
                $depNome = $dep['nome'] ?? '—';
                if (!isset($totaisDeposito[$depId])) {
                    $totaisDeposito[$depId] = ['nome' => $depNome, 'quantidade' => 0, 'valor' => 0.0];
                }
                $totaisDeposito[$depId]['quantidade'] += (int) ($dep['quantidade'] ?? 0);
                $totaisDeposito[$depId]['valor']      += (float) ($dep['valor'] ?? 0);
            }
        }
        uasort($totaisDeposito, fn($a, $b) => strcmp($a['nome'], $b['nome']));
    @endphp

    <table width="100%" cellspacing="0" cellpadding="6" border="1">
        <thead>
        <tr>
            @if($somenteOutlet)
                <th style="width: 80px;">Imagem</th>
            @endif
            <th>Produto</th>
            <th>Categoria</th>
            <th style="width: 120px;">Estoque Total</th>
            <th style="width: 150px;">Valor Total (R$)</th>
            <th>Por Depósito</th>
        </tr>
        </thead>
        <tbody>
        @foreach($dados as $produto => $info)
            <tr>
                @if($somenteOutlet)
                    <td style="text-align:center;">
                        @php
                            $imgRel = trim((string)($info['imagem_principal'] ?? ''));
                            $imgAbs = $imgRel !== '' ? $baseFsDir . DIRECTORY_SEPARATOR . $imgRel : '';
                        @endphp

                        @if($imgAbs !== '' && file_exists($imgAbs))
                            <img src="{{ 'file:///' . str_replace('\\', '/', $imgAbs) }}"
                                 alt="Imagem do produto" style="max-height:64px;">
                        @endif
                    </td>
                @endif

                <td>{{ $produto }}</td>
                <td>{{ $info['categoria'] ?? '—' }}</td>

                <td style="text-align: right">
                    {{ number_format((int)($info['estoque_total'] ?? 0), 0, ',', '.') }}
                </td>
                <td style="text-align: right">
                    {{ number_format((float)($info['valor_total'] ?? 0), 2, ',', '.') }}
                </td>
                <td>
                    @forelse(($info['estoque_por_deposito'] ?? []) as $dep)
                        {{ $dep['nome'] ?? '—' }}:
                        {{ number_format((int)($dep['quantidade'] ?? 0), 0, ',', '.') }}
                        — {{ number_format((float)($dep['valor'] ?? 0), 2, ',', '.') }}<br>
                    @empty
                        <span style="color:#666;">—</span>
                    @endforelse
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div style="height: 12px;"></div>

    <h3 style="margin: 8px 0 4px;">Totais por Depósito</h3>
    <table width="100%" cellspacing="0" cellpadding="6" border="1">
        <thead>
        <tr>
            <th>Depósito</th>
            <th style="width: 140px;">Quantidade</th>
            <th style="width: 180px;">Valor</th>
        </tr>
        </thead>
        <tbody>
        @forelse($totaisDeposito as $dep)
            <tr>
                <td>{{ $dep['nome'] }}</td>
                <td style="text-align: right">{{ number_format((int)$dep['quantidade'], 0, ',', '.') }}</td>
                <td style="text-align: right">R$ {{ number_format((float)$dep['valor'], 2, ',', '.') }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="3" style="text-align:center; color:#666;">Nenhum depósito encontrado para os filtros selecionados.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

    <div style="height: 8px;"></div>

    <h3 style="margin: 8px 0 4px;">Total Geral</h3>
    <table width="100%" cellspacing="0" cellpadding="6" border="1">
        <thead>
        <tr>
            <th style="width: 140px;">Quantidade</th>
            <th style="width: 180px;">Valor</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td style="text-align: right">{{ number_format((int)$totalGeralQuantidade, 0, ',', '.') }}</td>
            <td style="text-align: right">R$ {{ number_format((float)$totalGeralValor, 2, ',', '.') }}</td>
        </tr>
        </tbody>
    </table>
@endsection
