<?php

namespace App\Services\Import;

final class DimensoesParser
{
    /**
     * Extrai dimensões de um nome e retorna:
     * - w_cm, p_cm, a_cm (cm), diam_cm (opcional),
     * - clean (nome sem dimensões e sem texto depois das medidas),
     * - raw (texto que casou com as medidas),
     * - full (nome original)
     *
     * Suporta: "170x55x77cm", "220x100cm", "Ø 200cm", "300x400x10mm",
     *         "70X36CM", "Ø 70X36CM" (Ø combinado com medidas LxP).
     */
    public function extrair(string $nome): array
    {
        $src = trim($nome);
        $result = [
            'full' => $src,
            'raw' => null,
            'clean' => null,
            'w_cm' => null,
            'p_cm' => null,
            'a_cm' => null,
            'diam_cm' => null,
        ];

        if ($src === '') {
            $result['clean'] = '';
            return $result;
        }

        // Normalize spaces
        $s = preg_replace('/\s+/', ' ', $src);

        // Padrões: tentar achar o primeiro match que represente medidas
        // 1) Triplo com unidade opcional (cm/mm) e com x, X, ×
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*[xX×]\s*(\d+(?:[.,]\d+)?)\s*[xX×]\s*(\d+(?:[.,]\d+)?)(?:\s*(cm|mm))?/u', $s, $m, PREG_OFFSET_CAPTURE)) {
            $raw = $m[0][0];
            $pos = $m[0][1];
            $unit = isset($m[4][0]) ? strtolower($m[4][0]) : 'cm';
            [$w,$p,$a] = array_map([$this,'toFloat'], [$m[1][0],$m[2][0],$m[3][0]]);

            if ($unit === 'mm') {
                $w = $w / 10; $p = $p / 10; $a = $a / 10;
            }

            $result['raw'] = $raw;
            $result['w_cm'] = $w;
            $result['p_cm'] = $p;
            $result['a_cm'] = $a;
            $result['diam_cm'] = null;
            $result['clean'] = $this->cleanBeforeMatch($s, $pos);
            return $result;
        }

        // 2) Duplo LxP com unidade opcional (cm/mm) — note que pode existir Ø antes (ex: "Ø 70X36CM")
        if (preg_match('/(?:[ØØoØ]\s*)?(\d+(?:[.,]\d+)?)\s*[xX×]\s*(\d+(?:[.,]\d+)?)(?:\s*(cm|mm))?/ui', $s, $m, PREG_OFFSET_CAPTURE)) {
            // Se veio com Ø e duas medidas juntas (ex: "Ø 70X36CM"), tratar como LxP
            $raw = $m[0][0];
            $pos = $m[0][1];
            $unit = isset($m[3][0]) ? strtolower($m[3][0]) : 'cm';
            [$w,$p] = array_map([$this,'toFloat'], [$m[1][0],$m[2][0]]);

            if ($unit === 'mm') {
                $w = $w / 10; $p = $p / 10;
            }

            $result['raw'] = $raw;
            $result['w_cm'] = $w;
            $result['p_cm'] = $p;
            $result['a_cm'] = null;
            $result['diam_cm'] = null;
            $result['clean'] = $this->cleanBeforeMatch($s, $pos);
            return $result;
        }

        // 3) Ø diâmetro sozinho (ex: "Ø 200cm" ou "Ø200CM")
        if (preg_match('/[Øøo]\s*(\d+(?:[.,]\d+)?)(?:\s*(cm|mm))?/ui', $s, $m, PREG_OFFSET_CAPTURE)) {
            $raw = $m[0][0];
            $pos = $m[0][1];
            $unit = isset($m[2][0]) ? strtolower($m[2][0]) : 'cm';
            $d = $this->toFloat($m[1][0]);
            if ($unit === 'mm') $d = $d / 10;

            $result['raw'] = $raw;
            $result['diam_cm'] = $d;
            $result['w_cm'] = null;
            $result['p_cm'] = null;
            $result['a_cm'] = null;
            $result['clean'] = $this->cleanBeforeMatch($s, $pos);
            return $result;
        }

        // Nenhuma medida detectada
        $result['clean'] = $this->sanitizeName($s);
        return $result;
    }

    private function toFloat(string $v): float
    {
        return (float) str_replace(',', '.', $v);
    }

    /**
     * Retorna o texto antes do match, removendo Ø e sufixos de unidade do final
     * e limpando espaços duplicados.
     */
    private function cleanBeforeMatch(string $src, int $pos): string
    {
        $prefix = mb_substr($src, 0, $pos);
        return $this->sanitizeName($prefix);
    }

    /**
     * Remove Ø (e variantes), remove "CM"/"MM" remanescentes, e squish spaces.
     */
    private function sanitizeName(string $s): string
    {
        // Remove caracteres Ø e variantes
        $s = str_replace(['Ø','ø','º'], ' ', $s);
        // Remove sufixos 'cm' or 'mm' se sobrar por engano
        $s = preg_replace('/\b(cm|mm)\b/ui', ' ', $s);
        // Squish spaces
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }
}
