<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Configuracao
{
    /**
     * Recupera uma configuração inteira da tabela `configuracoes`.
     */
    public static function getInt(string $chave, int $default = 0): int
    {
        $valor = Cache::remember("cfg:$chave", 300, function () use ($chave) {
            return DB::table('configuracoes')->where('chave', $chave)->value('valor');
        });

        if ($valor === null) return $default;

        // aceita '120', '120.0', etc.
        return (int) floor((float) $valor);
    }
}
