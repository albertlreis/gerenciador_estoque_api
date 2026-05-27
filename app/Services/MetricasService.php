<?php

namespace App\Services;

class MetricasService
{
    public static function registrar(string $chave, string $origem, string $status, float $duracaoMs, ?int $usuarioId = null): void
    {
        app(AuditoriaLogService::class)->registrar([
            'occurred_at' => now(),
            'tipo' => 'metrica',
            'categoria' => 'metrica',
            'modulo' => 'monitoramento',
            'acao' => $origem,
            'status' => $status,
            'label' => $chave,
            'message' => "{$origem}: {$status}",
            'actor_id' => $usuarioId,
            'context_json' => [
                'chave' => $chave,
                'origem' => $origem,
                'duracao_ms' => round($duracaoMs, 2),
            ],
            'source_system' => 'estoque',
            'source_kind' => 'metric',
            'retention_days' => 90,
        ]);
    }
}
