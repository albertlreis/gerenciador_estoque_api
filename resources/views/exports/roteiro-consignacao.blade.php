@php use Carbon\Carbon; @endphp
    <!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Roteiro de Consignação</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            color: #000;
        }

        .header, .footer {
            text-align: center;
            margin-bottom: 10px;
        }

        .section {
            border: 1px solid #000;
            margin-bottom: 8px;
        }

        .section-title {
            background-color: #f3c000;
            font-weight: bold;
            padding: 4px;
            text-transform: uppercase;
        }

        .section-content table {
            width: 100%;
            border-collapse: collapse;
        }

        .section-content th,
        .section-content td {
            border: 1px solid #ccc;
            padding: 4px;
            font-size: 10px;
            vertical-align: top;
        }

        .obs {
            border: 1px solid #000;
            padding: 5px;
            height: 50px;
        }

        .assinatura {
            margin-top: 20px;
            border-top: 1px solid #000;
            padding-top: 4px;
            text-align: center;
        }

        .recebido {
            margin-top: 10px;
            padding: 6px;
            border: 1px solid #000;
            font-weight: bold;
            text-align: center;
            color: red;
        }

        .section-content img {
            display: block;
            margin: auto;
            border: 1px solid #ccc;
        }
    </style>
</head>
<body>
<div class="header">
    <img src="{{ public_path('logo.png') }}" width="120" alt="Logo"/>
    <h3>ROTEIRO DE CONSIGNAÇÃO</h3>
</div>

<table width="100%" style="margin-bottom: 10px;">
    <tr>
        <td><strong>DATA:</strong> {{ Carbon::now()->format('d/m/Y') }}</td>
        <td><strong>CONSULTORA:</strong> {{ $pedido->usuario->nome ?? '-' }}</td>
    </tr>
    <tr>
        <td><strong>CLIENTE:</strong> {{ $pedido->cliente->nome ?? '-' }}</td>
        <td><strong>END:</strong> {{ $pedido->cliente->endereco ?? '-' }}</td>
    </tr>
    <tr>
        <td><strong>TEL:</strong> {{ $pedido->cliente->telefone ?? '-' }}</td>
    </tr>
</table>

@foreach ($grupos as $deposito => $itens)
    <div class="section">
        <div class="section-title">{{ strtoupper($deposito) }}</div>
        <div class="section-content">
            <table>
                <thead>
                <tr>
                    <th>IMG</th>
                    <th>QTD</th>
                    <th>REF</th>
                    <th>DESCRIÇÃO</th>
                    <th>ACAB.</th>
                    <th>DATA ENVIO</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($itens as $item)
                    @php
                        $variacao = $item->produtoVariacao;
                        $produto = $variacao->produto;
                        $referencia = $variacao->referencia;
                        $acabamento = $variacao->atributos->pluck('valor')->implode(' / ');
                        $descricao = $variacao->nome_completo;
                        $dataEnvio = $item->data_envio ? Carbon::parse($item->data_envio)->format('d/m/Y') : '-';
                    @endphp
                    <tr>
                        <td>
                            @if($produto->imagemPrincipal)
                                <img src="{{ public_path('teste.png') }}" width="80" height="60" alt="Imagem produto"/>
                            @endif
                        </td>
                        <td>{{ $item->quantidade }}</td>
                        <td>{{ $referencia ?? '-' }}</td>
                        <td>{{ $descricao }}</td>
                        <td>{{ $acabamento }}</td>
                        <td>{{ $dataEnvio }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endforeach

<div style="margin-top: 10px;"><strong>OBS:</strong></div>
<div class="obs"></div>

<div class="recebido">
    RECEBIDO EM PERFEITO ESTADO NO ATO DA ENTREGA.<br>
    ASS: ________________________________________
</div>

<div class="footer">
    Clemente Salheb / Joseane Cunha<br>
    <strong>Sierra Belém</strong>
</div>
</body>
</html>
