@extends('layouts.pdf')

@section('titulo', 'Relatório de Consignações')

@section('conteudo')

    @php
        $consolidado = $consolidado ?? false;
        $totalGeral  = $totalGeral ?? 0;
    @endphp

    @if($consolidado)
        <table>
            <thead>
            <tr>
                <th>Cliente</th>
                <th>Total (R$)</th>
            </tr>
            </thead>
            <tbody>
            @foreach($dados as $linha)
                <tr>
                    <td>{{ $linha['cliente'] }}</td>
                    <td style="text-align:right">{{ number_format($linha['total'], 2, ',', '.') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <table>
            <thead>
            <tr>
                <th>Cliente</th>
                <th>Produto</th>
                <th>Data de Envio</th>
                <th>Vencimento</th>
                <th>Status</th>
                <th>Total (R$)</th>
            </tr>
            </thead>
            <tbody>
            @foreach($dados as $linha)
                <tr>
                    <td>{{ $linha['cliente'] }}</td>
                    <td>{{ $linha['produto'] }}</td>
                    <td>{{ $linha['data_envio_br'] }}</td>
                    <td>{{ $linha['vencimento_br'] }}</td>
                    <td>{{ $linha['status_label'] }}</td>
                    <td style="text-align:right">{{ number_format($linha['total'], 2, ',', '.') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

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
