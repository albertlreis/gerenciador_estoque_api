<?php

namespace App\Services\Assistencia;

use Illuminate\Support\Facades\DB;

/**
 * Gera números únicos para chamados de assistência.
 */
class NumeroChamadoGenerator
{
    public function gerar(): string
    {
        $ano = date('Y');
        $seq = DB::table('assistencia_chamados')
                ->whereYear('created_at', $ano)
                ->lockForUpdate()
                ->count() + 1;

        return sprintf('ASS-%s-%05d', $ano, $seq);
    }
}
