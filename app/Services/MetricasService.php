<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MetricasService
{
    public static function registrar(string $chave, string $origem, string $status, float $duracaoMs, ?int $usuarioId = null): void
    {
        DB::table('logs_metricas')->insert([
            'chave' => $chave,
            'origem' => $origem,
            'status' => $status,
            'usuario_id' => $usuarioId,
            'duracao_ms' => round($duracaoMs, 2),
            'criado_em' => now(),
        ]);
    }
}
