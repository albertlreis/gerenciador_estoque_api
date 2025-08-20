<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>@yield('titulo')</title>
    <style>
        /* Margens da página (papel) no DomPDF */
        @page {
            /* top/bottom = 15mm, left/right = 8mm */
            margin: 15mm 8mm;
            size: A4 landscape;
        }

        /* Espaço interno do conteúdo. Deixe pequeno para não adicionar "margem extra" */
        body {
            font-family: sans-serif;
            font-size: 12px;
            margin: 0;
            padding: 8px; /* estava 20px */
        }

        header {
            display: flex;
            align-items: center;
            margin-bottom: 12px; /* levemente menor que antes */
        }
        header img { height: 40px; margin-right: 12px; }
        header h1 { font-size: 18px; margin: 0; }

        footer {
            position: fixed;
            bottom: 8mm;   /* respeita a margem inferior da @page */
            left: 8mm;     /* respeita a margem esquerda da @page */
            right: 8mm;    /* respeita a margem direita da @page */
            font-size: 10px;
            color: #888;
            text-align: right;
        }

        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
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

                // Ajuste fino da posição se necessário após mudar as margens:
                $x = 520; // deslocamento horizontal
                $y = 810; // deslocamento vertical
                $pdf->text($x, $y, $pageText, $font, $size);
            }
        ');
    }
</script>

</body>
</html>
