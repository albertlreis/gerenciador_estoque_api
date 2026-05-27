<?php

namespace App\Services;

use App\Models\AuditoriaLog;
use App\Support\Auditoria\AuditoriaRedactor;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditoriaLogService
{
    /**
     * @param array<string,mixed> $data
     * @param array<int,array{campo:string,old?:mixed,new?:mixed,old_value?:mixed,new_value?:mixed,value_type?:string}> $mudancas
     */
    public function registrar(array $data, array $mudancas = []): ?AuditoriaLog
    {
        if (!$this->tableReady()) {
            return null;
        }

        $replaceExisting = (bool) ($data['replace_existing'] ?? false);
        $payload = $this->normalizePayload($data);

        if (!empty($payload['source_uid'])) {
            $existing = AuditoriaLog::query()
                ->where('source_uid', $payload['source_uid'])
                ->first();

            if ($existing && $replaceExisting) {
                $existing->fill($payload);
                $existing->save();

                return $existing->refresh();
            }

            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($payload, $mudancas) {
            $log = AuditoriaLog::query()->create($payload);
            $this->registrarMudancas($log, $mudancas);

            return $log;
        });
    }

    /**
     * @param array<int,array{campo:string,old?:mixed,new?:mixed,old_value?:mixed,new_value?:mixed,value_type?:string}> $mudancas
     */
    public function registrarMudancas(AuditoriaLog $log, array $mudancas): void
    {
        foreach ($mudancas as $mudanca) {
            $old = $mudanca['old'] ?? $mudanca['old_value'] ?? null;
            $new = $mudanca['new'] ?? $mudanca['new_value'] ?? null;
            $campo = (string) $mudanca['campo'];
            $redactedSecret = false;

            if (AuditoriaRedactor::isSecretKey($campo)) {
                $old = '[REDACTED]';
                $new = '[REDACTED]';
                $redactedSecret = true;
            }

            if ($old === $new && !$redactedSecret) {
                continue;
            }

            $log->mudancas()->create([
                'campo' => $campo,
                'old_value' => $this->stringify($old),
                'new_value' => $this->stringify($new),
                'value_type' => $mudanca['value_type'] ?? $this->inferType($new),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalizePayload(array $data): array
    {
        $actor = $data['actor'] ?? null;
        $entity = $data['entity'] ?? $data['auditable'] ?? null;
        $request = request();
        $user = auth()->user();

        $sourceTable = $data['source_table'] ?? null;
        $sourceId = $data['source_id'] ?? null;
        $sourceSystem = (string) ($data['source_system'] ?? 'estoque');
        $sourceKind = $data['source_kind'] ?? null;
        $categoria = (string) ($data['categoria'] ?? $this->categoriaForTipo((string) ($data['tipo'] ?? 'log')));

        $metadata = AuditoriaRedactor::redact($data['metadata_json'] ?? $data['metadata'] ?? null);
        $context = AuditoriaRedactor::redact($data['context_json'] ?? $data['context'] ?? null);

        return [
            'occurred_at' => $this->normalizeDate($data['occurred_at'] ?? $data['created_at'] ?? now()),
            'tipo' => (string) ($data['tipo'] ?? 'log'),
            'categoria' => $categoria,
            'nivel' => $this->nullableLower($data['nivel'] ?? $data['level'] ?? null),
            'modulo' => $this->nullableString($data['modulo'] ?? $data['module'] ?? null, 80),
            'acao' => $this->nullableString($data['acao'] ?? $data['action'] ?? null, 80),
            'status' => $this->nullableString($data['status'] ?? null, 60),
            'label' => $this->nullableString($data['label'] ?? null, 255),
            'message' => AuditoriaRedactor::truncateString($this->nullableString($data['message'] ?? null, 65000), 65000),
            'actor_type' => $this->nullableString($data['actor_type'] ?? ($actor instanceof Model ? get_class($actor) : ($user ? get_class($user) : null)), 120),
            'actor_id' => $data['actor_id'] ?? ($actor instanceof Model ? $actor->getKey() : $user?->id),
            'actor_name' => $this->nullableString($data['actor_name'] ?? ($user?->nome ?? $user?->name ?? $user?->email), 255),
            'entity_type' => $this->nullableString($data['entity_type'] ?? ($entity instanceof Model ? get_class($entity) : null), 190),
            'entity_id' => $this->nullableString($data['entity_id'] ?? ($entity instanceof Model ? $entity->getKey() : null), 120),
            'source_system' => $sourceSystem,
            'source_kind' => $this->nullableString($sourceKind, 60),
            'source_table' => $this->nullableString($sourceTable, 120),
            'source_id' => $this->nullableString($sourceId, 120),
            'source_uid' => $data['source_uid'] ?? ($sourceTable && $sourceId !== null ? self::sourceUid($sourceSystem, (string) $sourceKind, (string) $sourceTable, (string) $sourceId) : null),
            'origem' => $this->nullableString($data['origem'] ?? $data['origin'] ?? null, 60),
            'route' => $this->nullableString($data['route'] ?? (!app()->runningInConsole() ? $request?->path() : null), 255),
            'method' => $this->nullableString($data['method'] ?? (!app()->runningInConsole() ? $request?->method() : null), 10),
            'ip' => $this->nullableString($data['ip'] ?? (!app()->runningInConsole() ? $request?->ip() : null), 45),
            'user_agent' => AuditoriaRedactor::truncateString($this->nullableString($data['user_agent'] ?? (!app()->runningInConsole() ? $request?->userAgent() : null), 65000), 65000),
            'metadata_json' => $metadata,
            'context_json' => $context,
            'raw_excerpt' => AuditoriaRedactor::truncateString(AuditoriaRedactor::redactString((string) ($data['raw_excerpt'] ?? '')), 65000) ?: null,
            'retention_days' => (int) ($data['retention_days'] ?? $this->retentionFor($categoria)),
        ];
    }

    public static function sourceUid(string $sourceSystem, string $sourceKind, string $sourceTable, string $sourceId): string
    {
        return hash('sha256', implode('|', [$sourceSystem, $sourceKind, $sourceTable, $sourceId]));
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('auditoria_logs') && Schema::hasTable('auditoria_log_mudancas');
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeDate(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return now();
    }

    private function nullableLower(mixed $value): ?string
    {
        $value = $this->nullableString($value, 20);

        return $value === null ? null : strtolower($value);
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_scalar($value)) {
            return substr((string) $value, 0, $max);
        }

        return substr(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 0, $max) ?: null;
    }

    private function stringify(mixed $value): ?string
    {
        $value = AuditoriaRedactor::redact($value);

        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return AuditoriaRedactor::truncateString((string) $value, 65000);
        }

        return AuditoriaRedactor::truncateString(
            json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
            65000
        );
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

    private function categoriaForTipo(string $tipo): string
    {
        return match ($tipo) {
            'auditoria', 'evento' => 'negocio',
            'integracao' => 'integracao',
            'metrica' => 'metrica',
            'request' => 'request',
            default => 'tecnico',
        };
    }

    private function retentionFor(string $categoria): int
    {
        return in_array($categoria, ['negocio', 'integracao'], true) ? 365 : 90;
    }
}
