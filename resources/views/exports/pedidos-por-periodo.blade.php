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
        @foreach($dados as $pedido)
            <tr>
                <td>{{ $pedido['numero'] }}</td>
                <td>{{ $pedido['data'] }}</td>
                <td>{{ $pedido['cliente'] }}</td>
                <td>{{ $pedido['status']->label() }}</td>
                <td>{{ number_format($pedido['total'], 2, ',', '.') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
