<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

class FinanceiroAuditoriaService
{
    public function log(string $acao, Model $entidade, ?array $antes = null, ?array $depois = null): void
    {
        $usuarioId = auth()->id();

        $ip = null;
        $ua = null;

        if (!app()->runningInConsole()) {
            $req = request();
            $ip = $req?->ip();
            $ua = $req?->userAgent();
        }

        app(AuditoriaLogService::class)->registrar([
            'occurred_at' => now(),
            'tipo' => 'auditoria',
            'categoria' => 'negocio',
            'modulo' => 'financeiro',
            'acao' => $acao,
            'label' => 'Auditoria financeira',
            'message' => "Financeiro: {$acao}",
            'actor_id' => $usuarioId,
            'entity_type' => get_class($entidade),
            'entity_id' => (int) $entidade->getKey(),
            'ip' => $ip,
            'user_agent' => $ua,
            'context_json' => [
                'antes' => $this->truncateJson($antes),
                'depois' => $this->truncateJson($depois),
            ],
            'source_system' => 'estoque',
            'source_kind' => 'business_event',
            'retention_days' => 365,
        ]);
    }

    private function truncateJson(?array $data, int $max = 20000): ?array
    {
        if ($data === null) return null;

        $json = json_encode($data);
        if ($json === false) return ['__error' => 'json_encode_failed'];

        if (strlen($json) <= $max) return $data;

        return [
            '__truncated' => true,
            '__size' => strlen($json),
        ];
    }
}
