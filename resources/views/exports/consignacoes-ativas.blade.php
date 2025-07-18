@extends('layouts.pdf')

@section('titulo', 'Relatório de Consignações Ativas')

@section('conteudo')
    <table>
        <thead>
        <tr>
            <th>Cliente</th>
            <th>Data de Envio</th>
            <th>Vencimento</th>
            <th>Status</th>
            <th>Total (R$)</th>
        </tr>
        </thead>
        <tbody>
        @foreach($dados as $item)
            <tr>
                <td>{{ $item['cliente'] }}</td>
                <td>{{ $item['data_envio'] }}</td>
                <td>{{ $item['vencimento'] }}</td>
                <td>{{ ucfirst($item['status']) }}</td>
                <td>{{ number_format($item['total'], 2, ',', '.') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
