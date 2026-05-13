<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatorio de Lancamentos Financeiros</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 5px; text-align: left; }
        th { background: #f5f5f5; }
        .right { text-align: right; }
        .summary { margin-top: 8px; }
    </style>
</head>
<body>
<h2>Relatorio de Lancamentos Financeiros</h2>
<p>Gerado em: {{ $gerado_em }}</p>

<div class="summary">
    <strong>Receitas confirmadas:</strong> R$ {{ number_format((float) ($totais['receitas_confirmadas'] ?? 0), 2, ',', '.') }}
    &nbsp;|&nbsp;
    <strong>Despesas confirmadas:</strong> R$ {{ number_format((float) ($totais['despesas_confirmadas'] ?? 0), 2, ',', '.') }}
    &nbsp;|&nbsp;
    <strong>Saldo:</strong> R$ {{ number_format((float) ($totais['saldo_confirmado'] ?? 0), 2, ',', '.') }}
</div>

<table>
    <thead>
    <tr>
        <th>ID</th>
        <th>Tipo</th>
        <th>Descricao</th>
        <th>Categoria</th>
        <th>Conta</th>
        <th>Movimento</th>
        <th>Competencia</th>
        <th class="right">Valor</th>
        <th>Status</th>
    </tr>
    </thead>
    <tbody>
    @forelse($lancamentos as $lancamento)
        <tr>
            <td>{{ $lancamento->id }}</td>
            <td>{{ $lancamento->tipo?->value ?? $lancamento->tipo }}</td>
            <td>{{ $lancamento->descricao }}</td>
            <td>{{ $lancamento->categoria?->nome ?? '-' }}</td>
            <td>{{ $lancamento->conta?->nome ?? '-' }}</td>
            <td>{{ optional($lancamento->data_movimento)->format('d/m/Y H:i') }}</td>
            <td>{{ optional($lancamento->competencia)->format('d/m/Y') }}</td>
            <td class="right">{{ number_format((float) $lancamento->valor, 2, ',', '.') }}</td>
            <td>{{ $lancamento->status?->value ?? $lancamento->status }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="9">Nenhum lancamento encontrado.</td>
        </tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
