<?php

use App\Models\AuditoriaLog;
use App\Services\AuditoriaLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

if (!function_exists('logAuditoria')) {
    /**
     * Registra um log de auditoria padronizado.
     *
     * @param string $logName Nome do canal de log (ex: 'pedido', 'pedido_status').
     * @param string $descricao Texto descritivo (ex: "Pedido criado com sucesso").
     * @param array $properties Propriedades adicionais (ex: ['acao' => 'criação', 'nivel' => 'info']).
     * @param Model|null $model (Opcional) Modelo relacionado à ação.
     * @return AuditoriaLog|null
     */
    function logAuditoria(string $logName, string $descricao, array $properties = [], ?Model $model = null): ?AuditoriaLog
    {
        $usuario = Auth::user();

        return app(AuditoriaLogService::class)->registrar([
            'occurred_at' => now(),
            'tipo' => 'auditoria',
            'categoria' => 'negocio',
            'modulo' => $logName,
            'acao' => $properties['acao'] ?? 'activity',
            'label' => $descricao,
            'message' => $descricao,
            'actor_type' => $usuario ? get_class($usuario) : null,
            'actor_id' => $usuario?->id,
            'actor_name' => $usuario?->nome ?? $usuario?->name ?? $usuario?->email,
            'entity_type' => $model ? get_class($model) : null,
            'entity_id' => $model?->getKey(),
            'metadata_json' => array_merge([
                'usuario' => $usuario?->email,
            ], $properties),
            'source_system' => 'estoque',
            'source_kind' => 'business_event',
            'retention_days' => 365,
        ]);
    }
}
