<?php

namespace App\Services\Import;

final class LocalizacaoParser
{
    /**
     * Regras de localização (valor já virá limpo pela sua rotina):
     * - "setor-coluna-nivel": ex. "6-F1"  => setor=6, coluna=F, nivel=1
     * - "setor-coluna":       ex. "8-I"   => setor=8, coluna=I, nivel=null
     * - Qualquer outro texto: tratar como "área" (Assistência, Lavagem, etc.)
     *
     * Retorno:
     * [
     *   'setor'  => int|null,
     *   'coluna' => string|null,   // sempre uppercase quando presente
     *   'nivel'  => int|null,      // opcional
     *   'area'   => string|null,   // quando não casar com padrão posicional
     *   'codigo' => string|null,   // representação canônica
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

        // Padrões aceitos:
        // 1) setor-coluna-nivel: 6-F1 (nível opcional)
        //    - setor: dígitos
        //    - coluna: uma letra
        //    - nível: dígitos (opcional)
        //
        // Ex.: "6-F1", "6-F 1", "8-I"
        if (preg_match('/^\s*(\d+)\s*[-–]\s*([A-Za-z])\s*(\d+)?\s*$/u', $raw, $m)) {
            $setor  = (int)$m[1];
            $coluna = strtoupper($m[2]);
            $nivel  = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : null;

            // Monta código canônico sempre como "setor-COLUNA{nivel?}"
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

        // Caso contrário, considerar como ÁREA (ex.: Assistência, Lavagem, Base, Tampo...)
        return [
            'setor' => null, 'coluna' => null, 'nivel' => null,
            'area' => $raw, 'codigo' => $raw, 'tipo' => 'area'
        ];
    }
}
