<?php

namespace App\Services;

use App\Models\AuditoriaLog;
use Illuminate\Database\Eloquent\Model;

class AuditoriaEventoService
{
    /**
     * @param array<int,array{campo:string,old:mixed,new:mixed,value_type?:string}> $mudancas
     * @param array<string,mixed> $metadata
     */
    public function registrar(
        string $module,
        string $action,
        string $label,
        ?Model $auditable = null,
        array $mudancas = [],
        array $metadata = []
    ): ?AuditoriaLog {
        $usuario = auth()->user();
        $request = request();

        return app(AuditoriaLogService::class)->registrar([
            'occurred_at' => now(),
            'tipo' => 'auditoria',
            'categoria' => 'negocio',
            'modulo' => $module,
            'acao' => $action,
            'label' => $label,
            'message' => $label,
            'actor_type' => $usuario ? get_class($usuario) : null,
            'actor_id' => $usuario?->id,
            'actor_name' => $usuario?->nome ?? $usuario?->name ?? $usuario?->email,
            'entity_type' => $auditable ? get_class($auditable) : null,
            'entity_id' => $auditable?->getKey(),
            'route' => $request?->path(),
            'method' => $request?->method(),
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'origem' => 'API',
            'metadata_json' => empty($metadata) ? null : $metadata,
            'source_system' => 'estoque',
            'source_kind' => 'business_event',
            'retention_days' => 365,
        ], $mudancas);
    }
}
