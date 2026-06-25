<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Recibo</title>
    <style>
        @page { size: A4 portrait; margin: 18mm 17mm 20mm 17mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #111827;
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 13px;
            line-height: 1.35;
        }
        .top-line,
        .company-block {
            border-top: 2px solid #111827;
        }
        .company-block {
            border-bottom: 2px solid #111827;
            padding: 28px 0 26px;
        }
        .company-table,
        .title-table {
            width: 100%;
            border-collapse: collapse;
        }
        .company-table td,
        .title-table td {
            border: 0;
            padding: 0;
            vertical-align: middle;
        }
        .logo-cell {
            width: 112px;
            text-align: center;
        }
        .logo {
            width: 84px;
            max-height: 74px;
        }
        .company {
            color: #4b5563;
            font-size: 12px;
            line-height: 1.6;
            letter-spacing: .1px;
        }
        .company strong {
            display: block;
            color: #4b5563;
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 1px;
        }
        .receipt-head {
            padding-top: 20px;
        }
        .receipt-title {
            color: #0f172a;
            font-size: 28px;
            font-weight: 800;
            letter-spacing: 0;
        }
        .amount {
            text-align: right;
            color: #6b7280;
            white-space: nowrap;
        }
        .amount .currency {
            font-size: 17px;
            vertical-align: baseline;
        }
        .amount .value {
            color: #4b5563;
            font-size: 28px;
            font-weight: 300;
            margin-left: 6px;
        }
        .separator {
            border-top: 1px dashed #6b7280;
            margin: 18px 0 46px;
        }
        .body {
            font-size: 13.5px;
            line-height: 1.35;
            color: #111827;
        }
        .body p {
            margin: 0 0 6px;
        }
        .document {
            margin-top: 12px;
            color: #374151;
            font-size: 12px;
        }
        .date {
            margin-top: 42px;
            text-align: center;
            font-size: 13px;
        }
        .signature {
            margin: 70px auto 0;
            width: 76%;
            text-align: center;
        }
        .signature-line {
            border-top: 1px solid #111827;
            height: 9px;
        }
        .signature-name {
            font-size: 13px;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
@php
    $logoSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="90" viewBox="0 0 120 90"><text x="60" y="30" text-anchor="middle" font-family="serif" font-size="24" fill="#111">SR</text><text x="60" y="56" text-anchor="middle" font-family="serif" font-size="24" fill="#111">SIERRA</text><text x="60" y="72" text-anchor="middle" font-family="sans-serif" font-size="7" fill="#555">MOVEIS</text></svg>';
    $logo = !extension_loaded('gd') ? 'data:image/svg+xml;base64,' . base64_encode($logoSvg) : public_path('logo.png');
    $ie = trim((string) ($empresa['ie'] ?? ''));
@endphp

<div class="company-block">
    <table class="company-table">
        <tr>
            <td class="logo-cell">
                <img class="logo" src="{{ $logo }}" alt="Sierra">
            </td>
            <td class="company">
                <strong>{{ $empresa['nome'] }}</strong>
                CNPJ/CPF: {{ $empresa['documento'] }}
                @if($ie !== '')
                    &nbsp; IE: {{ $ie }}
                @endif
                @if(!empty($empresa['telefone']))
                    &nbsp; Tel: {{ $empresa['telefone'] }}
                @endif
                <br>
                {{ $empresa['endereco'] }}, {{ $empresa['cidade'] }} - {{ $empresa['uf'] }}<br>
                CEP: {{ $empresa['cep'] }}
            </td>
        </tr>
    </table>
</div>

<div class="receipt-head">
    <table class="title-table">
        <tr>
            <td class="receipt-title">Recibo</td>
            <td class="amount"><span class="currency">R$</span><span class="value">{{ $valor_formatado }}</span></td>
        </tr>
    </table>
</div>

<div class="separator"></div>

<div class="body">
    <p>{{ $texto }}</p>
    <p>Para confirmar a veracidade deste documento e da quantia paga, assino neste documento firmando o presente recibo nesta data.</p>
    @if(!empty($pessoa_documento))
        <p class="document"><strong>Documento:</strong> {{ $pessoa_documento }}</p>
    @endif
</div>

<div class="date">{{ $cidade_data }}</div>

<div class="signature">
    <div class="signature-line"></div>
    <div class="signature-name">{{ $assinatura }}</div>
</div>
</body>
</html>
