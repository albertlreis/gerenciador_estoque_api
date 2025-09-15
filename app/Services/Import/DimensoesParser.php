<?php

namespace App\Services\Import;

final class DimensoesParser
{
    /**
     * Extrai dimensões de um nome e retorna:
     * - w_cm, p_cm, a_cm (cm), diam_cm (opcional), clean (nome sem dimensões)
     * Suporta: "170x55x77cm", "220x100cm", "Ø 200cm", "300x400x10mm"
     */
    public function extrair(string $nome): array
    {
        $src = $nome;

        // Triplo em cm (LxPxA)
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*[xX×]\s*(\d+(?:[.,]\d+)?)\s*[xX×]\s*(\d+(?:[.,]\d+)?)(?:\s*cm)?/u', $src, $m)) {
            [$w,$p,$a] = array_map([$this,'toFloat'], [$m[1],$m[2],$m[3]]);
            $clean = trim(str_replace($m[0], '', $src));
            return ['w_cm'=>$w,'p_cm'=>$p,'a_cm'=>$a,'diam_cm'=>null,'clean'=>$clean];
        }

        // Triplo em mm
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*[xX×]\s*(\d+(?:[.,]\d+)?)\s*[xX×]\s*(\d+(?:[.,]\d+)?)(?:\s*mm)/ui', $src, $m)) {
            [$w,$p,$a] = array_map([$this,'toFloat'], [$m[1],$m[2],$m[3]]);
            $clean = trim(str_replace($m[0], '', $src));
            return ['w_cm'=>$w/10,'p_cm'=>$p/10,'a_cm'=>$a/10,'diam_cm'=>null,'clean'=>$clean];
        }

        // LxP
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*[xX×]\s*(\d+(?:[.,]\d+)?)(?:\s*cm)?/ui', $src, $m)) {
            [$w,$p] = array_map([$this,'toFloat'], [$m[1],$m[2]]);
            $clean = trim(str_replace($m[0], '', $src));
            return ['w_cm'=>$w,'p_cm'=>$p,'a_cm'=>null,'diam_cm'=>null,'clean'=>$clean];
        }

        // Ø diâmetro cm
        if (preg_match('/[Øøo]\s*(\d+(?:[.,]\d+)?)(?:\s*cm)/ui', $src, $m)) {
            $d = $this->toFloat($m[1]);
            $clean = trim(str_replace($m[0], '', $src));
            return ['w_cm'=>null,'p_cm'=>null,'a_cm'=>null,'diam_cm'=>$d,'clean'=>$clean];
        }

        return ['w_cm'=>null,'p_cm'=>null,'a_cm'=>null,'diam_cm'=>null,'clean'=>trim($src)];
    }

    private function toFloat(string $v): float
    {
        return (float) str_replace(',', '.', $v);
    }
}
