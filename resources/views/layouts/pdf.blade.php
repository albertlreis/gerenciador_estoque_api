<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>@yield('titulo')</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; margin: 0; padding: 20px; }
        header { display: flex; align-items: center; margin-bottom: 20px; }
        header img { height: 40px; margin-right: 15px; }
        header h1 { font-size: 18px; margin: 0; }

        footer {
            position: fixed;
            bottom: 10px;
            left: 20px;
            right: 20px;
            font-size: 10px;
            color: #888;
            text-align: right;
        }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        th { background: #f5f5f5; }
    </style>
</head>
<body>
<header>
    <img src="{{ public_path('logo.png') }}" alt="Logo">
    <h1>@yield('titulo')</h1>
</header>

@yield('conteudo')

<footer>
    Gerado em {{ now()->format('d/m/Y H:i:s') }}
</footer>

<script type="text/php">
    if (isset($pdf)) {
        $pdf->page_script('
            if ($PAGE_COUNT > 1) {
                $font = $fontMetrics->get_font("sans-serif", "normal");
                $size = 9;
                $pageText = "Página " . $PAGE_NUM . " de " . $PAGE_COUNT;
                $x = 520;
                $y = 810;
                $pdf->text($x, $y, $pageText, $font, $size);
            }
        ');
    }
</script>

</body>
</html>
