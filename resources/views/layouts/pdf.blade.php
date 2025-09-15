<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>@yield('titulo')</title>
    <style>
        /* Papel DomPDF (A4 paisagem). Mantemos margem inferior pequena. */
        @page {
            size: A4 landscape;
            margin-top: 12mm;
            margin-right: 8mm;
            margin-bottom: 14mm; /* pequeno para caber o footer */
            margin-left: 8mm;
        }

        body {
            font-family: sans-serif;
            font-size: 12px;
            margin: 0;
            padding: 8px;
        }

        header {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
        }
        header img { height: 40px; margin-right: 12px; }
        header h1 { font-size: 18px; margin: 0; }

        footer {
            position: fixed;
            bottom: 2mm;   /* cola no fim da página (respeita @page) */
            left: 8mm;
            right: 8mm;
            font-size: 10px;
            color: #888;
            display: flex;
            align-items: center;
            justify-content: space-between; /* data à esquerda, numeração à direita */
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
    <span>Gerado em {{ now()->format('d/m/Y H:i:s') }}</span>
    <!-- espaço para equilibrar o flex; a numeração real é desenhada via page_text -->
    <span style="opacity:0">Página</span>
</footer>

<script type="text/php">
    if (isset($pdf)) {
        /* Numeração: "Página X de Y" ancorada no canto inferior direito */
        $w = $pdf->get_width();
        $h = $pdf->get_height();

        $text = "Página {PAGE_NUM} de {PAGE_COUNT}";

        /* Fonte compatível em todas as instalações do DomPDF */
        $font = $fontMetrics->getFont("Helvetica", "normal");
        if (!$font) { $font = $fontMetrics->getFont("DejaVu Sans", "normal"); }

        $size = 9;

        /* Posição: ~20px acima da base e ~110px da borda direita (ajuste fino) */
        $x = $w - 110;
        $y = $h - 20;

        $pdf->page_text($x, $y, $text, $font, $size, array(0.53, 0.53, 0.53));
    }
</script>

</body>
</html>
