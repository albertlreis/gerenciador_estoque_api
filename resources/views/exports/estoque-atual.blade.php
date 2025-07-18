@extends('layouts.pdf')

@section('titulo', 'Relatório de Estoque Atual')

@section('conteudo')
    <table>
        <thead>
        <tr>
            <th>Produto</th>
            <th>Estoque Total</th>
            <th>Por Depósito</th>
        </tr>
        </thead>
        <tbody>
        @foreach($dados as $produto => $info)
            <tr>
                <td>{{ $produto }}</td>
                <td>{{ $info['estoque_total'] }}</td>
                <td>
                    @foreach($info['estoque_por_deposito'] as $dep => $qtd)
                        Depósito {{ $dep }}: {{ $qtd }}<br>
                    @endforeach
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
