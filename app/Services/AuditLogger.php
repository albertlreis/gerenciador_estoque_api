<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\Audit\AuditRequestContext;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AuditLogger
{
    private const SENSITIVE_KEYS = [
        'password',
        'senha',
        'token',
        'secret',
        'authorization',
        'api_key',
        'access_token',
        'refresh_token',
    ];

    public function log(
        string $action,
        Model|string $auditable,
        int|string|null $auditableId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null,
    ): void {
        if (!Schema::hasTable('audit_logs')) {
            return;
        }

        [$auditableType, $resolvedId] = $this->resolveAuditable($auditable, $auditableId);
        $context = $this->resolveContext();

        try {
            AuditLog::query()->create([
                'user_id' => $userId ?? auth()->id(),
                'action' => $action,
                'auditable_type' => $auditableType,
                'auditable_id' => $resolvedId,
                'route' => $context?->route,
                'method' => $context?->method,
                'ip' => $context?->ip,
                'user_agent' => $context?->userAgent ? mb_substr($context->userAgent, 0, 2000) : null,
                'old_values' => $this->sanitizePayload($oldValues),
                'new_values' => $this->sanitizePayload($newValues),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function logModel(
        string $action,
        Model $model,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null,
    ): void {
        $this->log(
            action: $action,
            auditable: $model,
            oldValues: $oldValues,
            newValues: $newValues,
            userId: $userId,
        );
    }

    private function resolveAuditable(Model|string $auditable, int|string|null $auditableId): array
    {
        if ($auditable instanceof Model) {
            return [get_class($auditable), $auditable->getKey()];
        }

        return [$auditable, $auditableId];
    }

    private function resolveContext(): ?AuditRequestContext
    {
        if (app()->bound(AuditRequestContext::class)) {
            return app(AuditRequestContext::class);
        }

        if (app()->runningInConsole()) {
            return null;
        }

        return AuditRequestContext::fromRequest(request());
    }

    private function sanitizePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return $this->sanitizeValue($payload);
    }

    private function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return '[redacted]';
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Model) {
            return $this->sanitizeValue($value->toArray(), $key);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $childKey => $childValue) {
                $normalizedKey = is_string($childKey) ? $childKey : null;
                $out[$childKey] = $this->sanitizeValue($childValue, $normalizedKey);
            }

            return $out;
        }

        if (is_object($value)) {
            return $this->sanitizeValue((array) $value, $key);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(trim($key));

        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (str_contains($normalized, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }
}
