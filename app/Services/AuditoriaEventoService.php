<?php

namespace App\Services;

use App\Models\AuditoriaEvento;
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
    ): AuditoriaEvento {
        $usuario = auth()->user();
        $request = request();

        $evento = AuditoriaEvento::create([
            'module' => $module,
            'action' => $action,
            'label' => $label,
            'actor_type' => $usuario ? get_class($usuario) : null,
            'actor_id' => $usuario?->id,
            'actor_name' => $usuario?->nome ?? $usuario?->name,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable?->getKey(),
            'route' => $request?->path(),
            'method' => $request?->method(),
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'origin' => 'API',
            'metadata_json' => empty($metadata) ? null : $metadata,
        ]);

        foreach ($mudancas as $mudanca) {
            $old = $mudanca['old'] ?? null;
            $new = $mudanca['new'] ?? null;

            if ($old === $new) {
                continue;
            }

            $evento->mudancas()->create([
                'campo' => $mudanca['campo'],
                'old_value' => $this->stringify($old),
                'new_value' => $this->stringify($new),
                'value_type' => $mudanca['value_type'] ?? $this->inferType($new),
            ]);
        }

        return $evento;
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function inferType(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            is_array($value), is_object($value) => 'json',
            $value === null => 'null',
            default => 'string',
        };
    }
}
