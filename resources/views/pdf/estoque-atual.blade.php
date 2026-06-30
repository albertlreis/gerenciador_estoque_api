<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
        h2 { margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 4px; vertical-align: top; }
        th { background: #f3f4f6; font-weight: bold; }
    </style>
</head>
<body>

<h2>Estoque Atual</h2>

<table>
    <thead>
    <tr>
        @foreach($colunas as $label)
            <th>{{ $label }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @foreach($linhas as $linha)
        <tr>
            @foreach(array_keys($colunas) as $coluna)
                <td>{{ $linha[$coluna] ?? '-' }}</td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>

</body>
</html>
