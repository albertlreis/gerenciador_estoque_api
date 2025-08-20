@extends('layouts.pdf')

@section('titulo', 'Relatório de Estoque Atual')

@section('conteudo')

    @php
        $totaisDeposito = []; // [depId => ['nome' => ..., 'quantidade' => int, 'valor' => float]]
        $totalGeralQuantidade = 0;
        $totalGeralValor = 0.0;

        foreach ($dados as $produto => $info) {
            $totalGeralQuantidade += (int) ($info['estoque_total'] ?? 0);
            $totalGeralValor += (float) ($info['valor_total'] ?? 0);

            foreach (($info['estoque_por_deposito'] ?? []) as $dep) {
                $depId = $dep['id'] ?? null;
                $depNome = $dep['nome'] ?? '—';
                if (!isset($totaisDeposito[$depId])) {
                    $totaisDeposito[$depId] = [
                        'nome' => $depNome,
                        'quantidade' => 0,
                        'valor' => 0.0,
                    ];
                }
                $totaisDeposito[$depId]['quantidade'] += (int) ($dep['quantidade'] ?? 0);
                $totaisDeposito[$depId]['valor'] += (float) ($dep['valor'] ?? 0);
            }
        }
        // Ordena por nome de depósito para melhor leitura (opcional)
        uasort($totaisDeposito, fn($a, $b) => strcmp($a['nome'], $b['nome']));
    @endphp

    {{-- Tabela principal por produto --}}
    <table width="100%" cellspacing="0" cellpadding="6" border="1">
        <thead>
        <tr>
            <th>Produto</th>
            <th>Estoque Total</th>
            <th>Valor Total (R$)</th>
            <th>Por Depósito</th>
        </tr>
        </thead>
        <tbody>
        @foreach($dados as $produto => $info)
            <tr>
                <td>{{ $produto }}</td>
                <td style="text-align: right">
                    {{ number_format((int)($info['estoque_total'] ?? 0), 0, ',', '.') }}
                </td>
                <td style="text-align: right">
                    {{ number_format((float)($info['valor_total'] ?? 0), 2, ',', '.') }}
                </td>
                <td>
                    @foreach(($info['estoque_por_deposito'] ?? []) as $dep)
                        {{-- Depósito Nome: Quantidade — Valor --}}
                        {{ $dep['nome'] ?? '—' }}:
                        {{ number_format((int)($dep['quantidade'] ?? 0), 0, ',', '.') }}
                        — {{ number_format((float)($dep['valor'] ?? 0), 2, ',', '.') }}<br>
                    @endforeach
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    {{-- Espaço antes dos totalizadores --}}
    <div style="height: 12px;"></div>

    {{-- Totais por Depósito (somatório de todos os produtos) --}}
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
                <td style="text-align: right">
                    {{ number_format((int)$dep['quantidade'], 0, ',', '.') }}
                </td>
                <td style="text-align: right">
                    R$ {{ number_format((float)$dep['valor'], 2, ',', '.') }}
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="3" style="text-align:center; color:#666;">Nenhum depósito encontrado para os filtros selecionados.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

    {{-- Espaço antes do total geral --}}
    <div style="height: 8px;"></div>

    {{-- Total Geral --}}
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
            <td style="text-align: right">
                {{ number_format((int)$totalGeralQuantidade, 0, ',', '.') }}
            </td>
            <td style="text-align: right">
                R$ {{ number_format((float)$totalGeralValor, 2, ',', '.') }}
            </td>
        </tr>
        </tbody>
    </table>

@endsection
