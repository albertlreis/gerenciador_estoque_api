<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Relatório de Assistências</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h2 { margin: 0 0 8px 0; }
        .meta { font-size: 10px; color: #444; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 6px; vertical-align: top; }
        th { background: #f3f3f3; font-weight: bold; }
        .small { font-size: 10px; color: #555; }
        .nowrap { white-space: nowrap; }
    </style>
</head>
<body>
<h2>Relatório de Assistências</h2>

<div class="meta">
    <div>
        <strong>Total:</strong> {{ $totais['total'] ?? 0 }} |
        <strong>Abertos:</strong> {{ $totais['abertos'] ?? 0 }} |
        <strong>Concluídos:</strong> {{ $totais['concluidos'] ?? 0 }}
    </div>

    @if(!empty($totais['por_status']))
        <div class="small" style="margin-top:4px;">
            <strong>Por status:</strong>
            @foreach($totais['por_status'] as $st => $qt)
                <span style="margin-right:10px;">{{ $st }}: {{ $qt }}</span>
            @endforeach
        </div>
    @endif
</div>

<table>
    <thead>
    <tr>
        <th class="nowrap">Chamado</th>
        <th>Status</th>
        <th class="nowrap">Abertura</th>
        <th class="nowrap">Conclusão</th>
        <th class="nowrap">Local</th>
        <th class="nowrap">Custo</th>
        <th>Assistência</th>
        <th>Pedido</th>
        <th>Cliente</th>
        <th>Fornecedor</th>
    </tr>
    </thead>
    <tbody>
    @forelse($dados as $r)
        <tr>
            <td class="nowrap">{{ $r['numero'] ?? '' }}</td>
            <td>{{ $r['status'] ?? '' }}</td>
            <td class="nowrap">{{ $r['aberto_em_br'] ?? '' }}</td>
            <td class="nowrap">{{ $r['concluido_em_br'] ?? '' }}</td>
            <td class="nowrap">{{ $r['local_reparo'] ?? '' }}</td>
            <td class="nowrap">{{ $r['custo_resp'] ?? '' }}</td>
            <td>{{ $r['assistencia'] ?? '' }}</td>
            <td class="nowrap">{{ $r['pedido_numero'] ?? ($r['pedido_id'] ?? '') }}</td>
            <td>{{ $r['cliente'] ?? '' }}</td>
            <td>{{ $r['fornecedor'] ?? '' }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="10" style="text-align:center;">Nenhum registro encontrado.</td>
        </tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
