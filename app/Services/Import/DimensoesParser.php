<?php

namespace App\Services\Import;

use Illuminate\Support\Str;

final class DimensoesParser
{
    /**
     * Extrai dimensões de um nome e retorna:
     * - w_cm, p_cm, a_cm (cm), diam_cm (opcional),
     * - comp_cm, esp_cm (opcionais, quando detectar),
     * - clean (nome sem as dimensões detectadas),
     * - raw (trecho detectado),
     * - full (nome original)
     *
     * Suporta:
     * - "170x55x77cm", "220x100cm", "300x400x10mm"
     * - "120cm x 60cm x 75cm"
     * - "Ø 200cm", "Ø200", "DIAM 120"
     * - L/A/P rotulados (ex.: "L 120 A 75 P 60", "L:120cm A:75cm P:60cm")
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
            'comp_cm' => null,
            'esp_cm' => null,
        ];

        if ($src === '') {
            $result['clean'] = '';
            return $result;
        }

        $s = preg_replace('/\s+/', ' ', $src);

        // 1) LxPxA (triplo) com unidade opcional, aceitando unidade por número também
        if (preg_match('/(\d+(?:[.,]\d+)?)(?:\s*(cm|mm))?\s*[xX×]\s*(\d+(?:[.,]\d+)?)(?:\s*(cm|mm))?\s*[xX×]\s*(\d+(?:[.,]\d+)?)(?:\s*(cm|mm))?/u', $s, $m, PREG_OFFSET_CAPTURE)) {
            $raw = $m[0][0];
            $pos = $m[0][1];

            $w = $this->toFloat($m[1][0]);
            $p = $this->toFloat($m[3][0]);
            $a = $this->toFloat($m[5][0]);

            $unit1 = isset($m[2][0]) && $m[2][0] !== '' ? strtolower($m[2][0]) : null;
            $unit2 = isset($m[4][0]) && $m[4][0] !== '' ? strtolower($m[4][0]) : null;
            $unit3 = isset($m[6][0]) && $m[6][0] !== '' ? strtolower($m[6][0]) : null;

            $w = $this->convertToCm($w, $unit1);
            $p = $this->convertToCm($p, $unit2 ?? $unit1);
            $a = $this->convertToCm($a, $unit3 ?? $unit2 ?? $unit1);

            $result['raw'] = $raw;
            $result['w_cm'] = $w;
            $result['p_cm'] = $p;
            $result['a_cm'] = $a;
            $result['clean'] = $this->cleanRemovingMatch($s, $pos, mb_strlen($raw));

            return $result;
        }

        // 2) LxP (duplo) com unidade opcional
        if (preg_match('/(\d+(?:[.,]\d+)?)(?:\s*(cm|mm))?\s*[xX×]\s*(\d+(?:[.,]\d+)?)(?:\s*(cm|mm))?/u', $s, $m, PREG_OFFSET_CAPTURE)) {
            $raw = $m[0][0];
            $pos = $m[0][1];

            $w = $this->toFloat($m[1][0]);
            $p = $this->toFloat($m[3][0]);

            $unit1 = isset($m[2][0]) && $m[2][0] !== '' ? strtolower($m[2][0]) : null;
            $unit2 = isset($m[4][0]) && $m[4][0] !== '' ? strtolower($m[4][0]) : null;

            $w = $this->convertToCm($w, $unit1);
            $p = $this->convertToCm($p, $unit2 ?? $unit1);

            $result['raw'] = $raw;
            $result['w_cm'] = $w;
            $result['p_cm'] = $p;
            $result['a_cm'] = null;
            $result['clean'] = $this->cleanRemovingMatch($s, $pos, mb_strlen($raw));

            return $result;
        }

        // 3) Ø diâmetro sozinho (Ø / DIAM / DIÂM / DIAMETRO)
        if (preg_match('/(?:[Øøº]\s*|(?:\bdi[aâ]m(?:etro)?\.?\b)\s*[:=]?\s*)(\d+(?:[.,]\d+)?)(?:\s*(cm|mm))?/ui', $s, $m, PREG_OFFSET_CAPTURE)) {
            $raw = $m[0][0];
            $pos = $m[0][1];

            $unit = isset($m[2][0]) && $m[2][0] !== '' ? strtolower($m[2][0]) : null;
            $d = $this->toFloat($m[1][0]);
            $d = $this->convertToCm($d, $unit);

            $result['raw'] = $raw;
            $result['diam_cm'] = $d;
            $result['clean'] = $this->cleanRemovingMatch($s, $pos, mb_strlen($raw));

            return $result;
        }

        // 4) Rotulados: L/LARG, A/ALT, P/PROF (ordem livre)
        if (preg_match_all('/\b(larg(?:ura)?|l|alt(?:ura)?|a|prof(?:undidade)?|p|comp(?:rimento)?|c|esp(?:essura)?|e)\b\s*[:=]?\s*(\d+(?:[.,]\d+)?)(?:\s*(cm|mm))?/ui', $s, $all, PREG_SET_ORDER)) {
            $w = $p = $a = null;
            $comp = $esp = null;
            $rawParts = [];

            foreach ($all as $m) {
                $label = strtolower($this->ascii($m[1]));
                $val = $this->toFloat($m[2]);
                $unit = isset($m[3]) && $m[3] !== '' ? strtolower($m[3]) : null;
                $val = $this->convertToCm($val, $unit);

                $rawParts[] = $m[0];

                if (in_array($label, ['l', 'larg', 'largura'], true)) $w = $val;
                if (in_array($label, ['a', 'alt', 'altura'], true)) $a = $val;
                if (in_array($label, ['p', 'prof', 'profundidade'], true)) $p = $val;

                if (in_array($label, ['c', 'comp', 'comprimento'], true)) $comp = $val;
                if (in_array($label, ['e', 'esp', 'espessura'], true)) $esp = $val;
            }

            if ($w !== null || $p !== null || $a !== null || $comp !== null || $esp !== null) {
                $result['w_cm'] = $w;
                $result['p_cm'] = $p;
                $result['a_cm'] = $a;
                $result['comp_cm'] = $comp;
                $result['esp_cm'] = $esp;

                $result['raw'] = implode(' ', $rawParts);

                $clean = $s;
                foreach ($rawParts as $part) {
                    $clean = str_ireplace($part, ' ', $clean);
                }

                $result['clean'] = $this->sanitizeName($clean);
                return $result;
            }
        }

        // Nenhuma medida detectada
        $result['clean'] = $this->sanitizeName($s);
        return $result;
    }

    private function toFloat(string $v): float
    {
        $v = trim($v);
        if (str_contains($v, '.') && str_contains($v, ',')) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } else {
            $v = str_replace(',', '.', $v);
        }

        return (float)$v;
    }

    private function convertToCm(float $value, ?string $unit): float
    {
        $u = $unit ? strtolower($unit) : 'cm';

        if ($u === 'mm') return $value / 10;
        return $value; // cm padrão
    }

    private function cleanRemovingMatch(string $src, int $pos, int $len): string
    {
        $before = mb_substr($src, 0, $pos);
        $after  = mb_substr($src, $pos + $len);

        $s = $before . ' ' . $after;

        return $this->sanitizeName($s);
    }

    private function sanitizeName(string $s): string
    {
        $s = str_replace(['Ø', 'ø', 'º'], ' ', $s);

        // Remove unidades remanescentes se sobrarem
        $s = preg_replace('/\b(cm|mm)\b/ui', ' ', $s);

        // remove "MED", "MED.", "MEDIDA(S)" que costumam sobrar após remover dimensões
        $s = preg_replace('/\bmed\b|\bmed\.\b|\bmedida\b|\bmedidas\b/ui', ' ', $s);

        $s = preg_replace('/\s+/', ' ', $s);

        return trim($s);
    }

    private function ascii(string $s): string
    {
        return (string) Str::of($s)->ascii();
    }
}
