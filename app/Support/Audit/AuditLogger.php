<?php

namespace App\Support\Audit;

use App\Models\AuditoriaEvento;
use App\Models\AuditoriaMudanca;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Throwable;

class AuditLogger
{
    public function __construct(private readonly AuditContext $context) {}

    public function logCreate(Model $model, string $module, string $label, array $metadata = []): ?AuditoriaEvento
    {
        if ($this->isAuditModel($model)) {
            return null;
        }

        try {
            $ignored = $this->ignoredFields();
            $changes = [];

            foreach ($model->getAttributes() as $field => $value) {
                if (in_array($field, $ignored, true)) {
                    continue;
                }

                if ($this->isSensitiveField($field, $model::class)) {
                    continue;
                }

                $changes[$field] = [
                    'old' => null,
                    'new' => $value,
                ];
            }

            return $this->logCustom(
                class_basename($model),
                $model->getKey(),
                $module,
                'CREATE',
                $label,
                $changes,
                $metadata
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    public function logUpdate(
        Model $model,
        string $module,
        string $label,
        array $metadata = [],
        array $ignoreFields = []
    ): ?AuditoriaEvento {
        if ($this->isAuditModel($model)) {
            return null;
        }

        try {
            $before = Arr::pull($metadata, '__before');
            $dirtySnapshot = Arr::pull($metadata, '__dirty');

            $ignored = array_values(array_unique(array_merge($this->ignoredFields(), $ignoreFields)));

            $dirty = is_array($dirtySnapshot) ? $dirtySnapshot : $model->getDirty();
            if (empty($dirty)) {
                $dirty = Arr::except($model->getChanges(), $ignored);
            }

            $changes = [];
            foreach ($dirty as $field => $newValue) {
                if (in_array($field, $ignored, true)) {
                    continue;
                }

                if ($this->isSensitiveField($field, $model::class)) {
                    continue;
                }

                $oldValue = is_array($before) && array_key_exists($field, $before)
                    ? $before[$field]
                    : $model->getOriginal($field);

                if ($this->serializeValue($oldValue) === $this->serializeValue($newValue)) {
                    continue;
                }

                $changes[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }

            if (empty($changes)) {
                return null;
            }

            return $this->logCustom(
                class_basename($model),
                $model->getKey(),
                $module,
                'UPDATE',
                $label,
                $changes,
                $metadata
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    public function logDelete(Model $model, string $module, string $label, array $metadata = []): ?AuditoriaEvento
    {
        if ($this->isAuditModel($model)) {
            return null;
        }

        try {
            $ignored = $this->ignoredFields();
            $changes = [];

            foreach ($model->getAttributes() as $field => $value) {
                if (in_array($field, $ignored, true)) {
                    continue;
                }

                if ($this->isSensitiveField($field, $model::class)) {
                    continue;
                }

                $changes[$field] = [
                    'old' => $value,
                    'new' => null,
                ];
            }

            return $this->logCustom(
                class_basename($model),
                $model->getKey(),
                $module,
                'DELETE',
                $label,
                $changes,
                $metadata
            );
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    public function logCustom(
        $auditableType,
        $auditableId,
        $module,
        $action,
        $label,
        $changes = [],
        $metadata = []
    ): ?AuditoriaEvento {
        try {
            if (!is_numeric($auditableId)) {
                return null;
            }

            $eventData = $this->buildEventData(
                (string) $auditableType,
                (int) $auditableId,
                (string) $module,
                strtoupper((string) $action),
                (string) $label,
                is_array($metadata) ? $metadata : []
            );

            $event = AuditoriaEvento::create($eventData);

            foreach ($this->normalizeChanges($changes) as $change) {
                $field = (string) ($change['field'] ?? '');
                if ($field === '' || $this->isSensitiveField($field)) {
                    continue;
                }

                AuditoriaMudanca::create([
                    'evento_id' => $event->id,
                    'field' => $field,
                    'old_value' => $this->serializeValue($change['old'] ?? null),
                    'new_value' => $this->serializeValue($change['new'] ?? null),
                    'value_type' => $this->detectValueType($change['new'] ?? $change['old'] ?? null),
                ]);
            }

            return $event;
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    private function buildEventData(
        string $auditableType,
        int $auditableId,
        string $module,
        string $action,
        string $label,
        array $metadata
    ): array {
        [$actorType, $actorId, $actorName] = $this->resolveActor();
        $metadataSanitized = $this->sanitizeArray($metadata);

        $prevHash = AuditoriaEvento::query()->orderByDesc('id')->value('event_hash');
        $eventHashPayload = [
            'request_id' => $this->context->getRequestId(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'module' => $module,
            'action' => $action,
            'label' => $label,
            'metadata' => $metadataSanitized,
        ];

        return [
            'created_at' => now(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'module' => $module,
            'action' => $action,
            'label' => $label,
            'request_id' => $this->context->getRequestId(),
            'route' => $this->context->getRoute(),
            'method' => $this->context->getMethod(),
            'ip' => $this->context->getIp(),
            'user_agent' => $this->limitString($this->context->getUserAgent(), 4000),
            'origin' => $this->resolveOrigin(),
            'metadata_json' => $metadataSanitized,
            'prev_hash' => $prevHash,
            'event_hash' => hash('sha256', ($prevHash ?? '') . '|' . json_encode($eventHashPayload)),
        ];
    }

    private function resolveOrigin(): string
    {
        if (app()->runningInConsole()) {
            $origin = $this->context->getOrigin();
            return $origin === 'API' ? 'JOB' : ($origin ?: 'JOB');
        }

        return $this->context->getOrigin() ?: 'API';
    }

    /** @return array{0:string,1:int|null,2:string|null} */
    private function resolveActor(): array
    {
        $actor = $this->context->getActor();
        if (!$actor && Auth::check()) {
            $user = Auth::user();
            $actor = [
                'type' => 'USER',
                'id' => $user?->getAuthIdentifier(),
                'name' => $user?->nome ?? $user?->name ?? $user?->email ?? null,
            ];
        }

        if (!$actor) {
            return ['SYSTEM', null, 'SYSTEM'];
        }

        $actorId = is_numeric($actor['id'] ?? null) ? (int) $actor['id'] : null;

        return [
            strtoupper((string) ($actor['type'] ?? 'SYSTEM')),
            $actorId,
            $this->limitString($actor['name'] ?? null, 255),
        ];
    }

    private function ignoredFields(): array
    {
        return config('auditoria.default_ignored_fields', []);
    }

    private function isSensitiveField(string $field, ?string $modelClass = null): bool
    {
        $fieldNormalized = strtolower(trim($field));

        $globalSensitive = array_map(
            static fn ($item) => strtolower((string) $item),
            config('auditoria.sensitive_fields', [])
        );

        if (in_array($fieldNormalized, $globalSensitive, true)) {
            return true;
        }

        if (!$modelClass) {
            return false;
        }

        $entityMap = config('auditoria.entity_sensitive_fields', []);
        $entitySensitive = array_map(
            static fn ($item) => strtolower((string) $item),
            $entityMap[$modelClass] ?? []
        );

        return in_array($fieldNormalized, $entitySensitive, true);
    }

    /**
     * @param array<int|string,mixed> $changes
     * @return array<int,array{field:string,old:mixed,new:mixed}>
     */
    private function normalizeChanges(array $changes): array
    {
        $normalized = [];

        foreach ($changes as $field => $payload) {
            if (is_int($field) && is_array($payload) && array_key_exists('field', $payload)) {
                $normalized[] = [
                    'field' => (string) $payload['field'],
                    'old' => $payload['old'] ?? null,
                    'new' => $payload['new'] ?? null,
                ];
                continue;
            }

            if (!is_string($field)) {
                continue;
            }

            if (is_array($payload) && (array_key_exists('old', $payload) || array_key_exists('new', $payload))) {
                $normalized[] = [
                    'field' => $field,
                    'old' => $payload['old'] ?? null,
                    'new' => $payload['new'] ?? null,
                ];
                continue;
            }

            $normalized[] = [
                'field' => $field,
                'old' => null,
                'new' => $payload,
            ];
        }

        return $normalized;
    }

    /** @param array<string,mixed> $value */
    private function sanitizeArray(array $value): array
    {
        $output = [];

        foreach ($value as $key => $item) {
            $keyString = (string) $key;
            if ($this->isSensitiveField($keyString)) {
                continue;
            }

            if (is_array($item)) {
                $output[$keyString] = $this->sanitizeArray($item);
                continue;
            }

            if (is_object($item)) {
                $output[$keyString] = $this->sanitizeArray((array) $item);
                continue;
            }

            $output[$keyString] = $item;
        }

        return $output;
    }

    private function detectValueType(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if ($value instanceof DateTimeInterface) {
            return 'date';
        }

        if (is_bool($value)) {
            return 'bool';
        }

        if (is_int($value) || is_float($value)) {
            return 'number';
        }

        if (is_array($value) || is_object($value)) {
            return 'json';
        }

        return 'string';
    }

    private function serializeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $this->limitString((string) $value, 65000);
    }

    private function limitString(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function isAuditModel(Model $model): bool
    {
        return $model instanceof AuditoriaEvento || $model instanceof AuditoriaMudanca;
    }
}
