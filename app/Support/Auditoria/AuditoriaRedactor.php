<?php

namespace App\Support\Auditoria;

use DateTimeInterface;
use JsonSerializable;
use Stringable;
use Throwable;

class AuditoriaRedactor
{
    private const SECRET_KEYS = [
        'senha',
        'password',
        'current_password',
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'api_key',
        'apikey',
        'secret',
        'client_secret',
        'remember_token',
    ];

    public static function redact(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return '[MAX_DEPTH]';
        }

        if (is_array($value)) {
            return self::redactArray($value, $depth + 1);
        }

        if (is_object($value)) {
            if ($value instanceof Throwable) {
                return [
                    'class' => get_class($value),
                    'message' => self::truncateString(self::redactString($value->getMessage())),
                    'code' => $value->getCode(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
            }

            if ($value instanceof DateTimeInterface) {
                return $value->format(DATE_ATOM);
            }

            if ($value instanceof JsonSerializable) {
                return self::redact($value->jsonSerialize(), $depth + 1);
            }

            if ($value instanceof Stringable) {
                return self::truncateString(self::redactString((string) $value));
            }

            return ['class' => get_class($value)];
        }

        if (is_string($value)) {
            return self::truncateString(self::redactString($value));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function redactArray(array $data, int $depth = 0): array
    {
        $redacted = [];

        foreach (array_slice($data, 0, 100, true) as $key => $value) {
            if (self::isSecretKey((string) $key)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }

            $redacted[$key] = self::redact($value, $depth + 1);
        }

        if (count($data) > 100) {
            $redacted['__truncated_items'] = count($data) - 100;
        }

        return $redacted;
    }

    public static function redactString(string $value): string
    {
        $patterns = [
            '/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i',
            '/((?:senha|password|token|access_token|refresh_token|authorization|cookie|api_key|client_secret|secret)\s*[=:]\s*)([^\s,;&]+)/i',
            '/("?(?:senha|password|token|access_token|refresh_token|authorization|cookie|api_key|client_secret|secret)"?\s*:\s*")[^"]*(")/i',
        ];

        $replacements = [
            '$1[REDACTED]',
            '$1[REDACTED]',
            '$1[REDACTED]$2',
        ];

        return (string) preg_replace($patterns, $replacements, $value);
    }

    public static function truncateString(?string $value, int $max = 20000): ?string
    {
        if ($value === null || strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max) . '...[truncated]';
    }

    public static function isSecretKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SECRET_KEYS as $secret) {
            if ($normalized === $secret || str_contains($normalized, $secret)) {
                return true;
            }
        }

        return false;
    }
}
