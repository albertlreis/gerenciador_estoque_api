<?php

namespace App\Services\Import;

use Illuminate\Support\Str;

final class NomeAtributosParser
{
    /**
     * Extrai:
     * - nome_base: texto antes do primeiro "-"
     * - atributos: texto depois do primeiro "-" (pode conter "TAMPO X", "PÉ Y", "MED ...")
     * - tampo_cor: se existir "TAMPO <algo>" (para antes de MED/MEDIDAS ou números)
     */
    public function extrair(string $nome): array
    {
        $full = trim($nome);

        if ($full === '') {
            return [
                'full' => '',
                'nome_base' => '',
                'atributos' => null,
                'tampo_cor' => null,
            ];
        }

        // separa por hífen (conservador)
        $parts = preg_split('/\s*-\s*/u', $full) ?: [];
        $nomeBase = trim((string)($parts[0] ?? $full));
        $attrsTxt = count($parts) > 1 ? trim(implode(' - ', array_slice($parts, 1))) : null;

        $tampo = $this->extrairTampoCor($attrsTxt);

        return [
            'full' => $full,
            'nome_base' => $nomeBase !== '' ? $nomeBase : $full,
            'atributos' => ($attrsTxt !== null && $attrsTxt !== '') ? $attrsTxt : null,
            'tampo_cor' => $tampo,
        ];
    }

    private function extrairTampoCor(?string $attrs): ?string
    {
        if (!$attrs) return null;

        $s = Str::of($attrs)->upper()->__toString();

        /**
         * Captura:
         * "TAMPO BRANCO MED 39X39X29CM" -> "BRANCO"
         * "TAMPO PRETO" -> "PRETO"
         * Para antes de:
         * - MED / MEDIDA(S)
         * - início de números (dimensões)
         */
        if (preg_match('/\bTAMPO\b\s+(.+?)(?=\s+\bMED\b|\s+\bMEDIDA\b|\s+\bMEDIDAS\b|\s+\d|\s*$)/u', $s, $m)) {
            $val = trim((string)($m[1] ?? ''));
            return $val !== '' ? $val : null;
        }

        return null;
    }
}
