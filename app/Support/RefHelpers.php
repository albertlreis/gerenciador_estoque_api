<?php

namespace App\Support;

final class RefHelpers
{
    /** Formata cProd: "1837[160596971]" -> "1837" */
    public static function formatarReferencia(?string $cProd): ?string
    {
        if (!$cProd) return null;
        // pega tudo antes de '[' e tira espaços
        $ref = preg_split('/\[/u', (string)$cProd, 2)[0] ?? $cProd;
        return trim($ref);
    }
}
