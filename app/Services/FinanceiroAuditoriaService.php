<?php

namespace App\Services;

use App\Models\FinanceiroAuditoria;
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

        FinanceiroAuditoria::create([
            'acao'          => $acao,
            'entidade_type' => get_class($entidade),
            'entidade_id'   => (int) $entidade->getKey(),
            'antes_json'    => $this->truncateJson($antes),
            'depois_json'   => $this->truncateJson($depois),
            'usuario_id'    => $usuarioId,
            'ip'            => $ip,
            'user_agent'    => $ua ? substr($ua, 0, 2000) : null,
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
