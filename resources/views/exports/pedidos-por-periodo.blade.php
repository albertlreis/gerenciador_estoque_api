@extends('layouts.pdf')

@section('titulo', 'Relatório de Pedidos por Período')

@section('conteudo')
    <table>
        <thead>
        <tr>
            <th>Número</th>
            <th>Data</th>
            <th>Cliente</th>
            <th>Status</th>
            <th>Total (R$)</th>
        </tr>
        </thead>
        <tbody>
        @php $totalGeral = $totalGeral ?? 0; @endphp
        @foreach($dados as $pedido)
            <tr>
                <td>{{ $pedido['numero'] }}</td>
                <td>{{ $pedido['data_br'] }}</td>
                <td>{{ $pedido['cliente'] }}</td>
                <td>{{ $pedido['status_label'] }}</td>
                <td style="text-align:right">{{ number_format($pedido['total'], 2, ',', '.') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div style="height:8px;"></div>

    <table width="100%" cellspacing="0" cellpadding="6" border="1">
        <thead>
        <tr>
            <th style="width: 80%">Total Geral</th>
            <th style="width: 20%">R$ {{ number_format($totalGeral, 2, ',', '.') }}</th>
        </tr>
        </thead>
    </table>
@endsection
