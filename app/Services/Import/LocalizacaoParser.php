<?php

namespace App\Services\Import;

final class LocalizacaoParser
{
    /**
     * Regras de localização:
     * - "setor-coluna-nivel": ex. "6-F1"  => setor=6, coluna=F, nivel=1
     * - "setor-coluna":       ex. "8-I"   => setor=8, coluna=I, nivel=null
     * - Qualquer outro texto: tratar como "área" (Assistência, Lavagem, etc.)
     *
     * Retorno:
     * [
     *   'setor'  => int|null,
     *   'coluna' => string|null,   // uppercase quando presente
     *   'nivel'  => int|null,
     *   'area'   => string|null,
     *   'codigo' => string|null,
     *   'tipo'   => 'posicao'|'area'|'vazio'
     * ]
     */
    public function parse(?string $raw): array
    {
        $raw = trim((string)$raw);

        if ($raw === '') {
            return [
                'setor' => null, 'coluna' => null, 'nivel' => null,
                'area' => null, 'codigo' => null, 'tipo' => 'vazio'
            ];
        }

        /**
         * Aceita:
         * - "6-F1", "6-F 1", "8-I"
         * - também tolera separador ausente: "6F1", "8I"
         * - letras 1..2 (caso exista AA/AB etc)
         */
        if (preg_match('/^\s*(\d+)\s*[-–—]?\s*([A-Za-z]{1,2})\s*(\d+)?\s*$/u', $raw, $m)) {
            $setor  = (int)$m[1];
            $coluna = strtoupper($m[2]);
            $nivel  = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : null;

            $codigo = $nivel === null
                ? sprintf('%d-%s', $setor, $coluna)
                : sprintf('%d-%s%d', $setor, $coluna, $nivel);

            return [
                'setor'  => $setor,
                'coluna' => $coluna,
                'nivel'  => $nivel,
                'area'   => null,
                'codigo' => $codigo,
                'tipo'   => 'posicao',
            ];
        }

        // Caso contrário, considerar como ÁREA
        return [
            'setor' => null, 'coluna' => null, 'nivel' => null,
            'area' => $raw, 'codigo' => $raw, 'tipo' => 'area'
        ];
    }
}
