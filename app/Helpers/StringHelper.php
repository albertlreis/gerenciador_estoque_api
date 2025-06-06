<?php

namespace App\Helpers;

class StringHelper
{
    /**
     * Normaliza o nome do atributo: remove acentos, transforma em minúsculo e substitui espaços por underline.
     */
    public static function normalizarAtributo(string $texto): string
    {
        $texto = mb_strtolower($texto, 'UTF-8');
        $texto = strtr($texto, [
            'á'=>'a', 'à'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a',
            'é'=>'e', 'è'=>'e', 'ê'=>'e', 'ë'=>'e',
            'í'=>'i', 'ì'=>'i', 'î'=>'i', 'ï'=>'i',
            'ó'=>'o', 'ò'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o',
            'ú'=>'u', 'ù'=>'u', 'û'=>'u', 'ü'=>'u',
            'ç'=>'c',
        ]);
        $texto = preg_replace('/[^a-z0-9]+/i', '_', $texto);
        return trim($texto, '_');
    }
}
