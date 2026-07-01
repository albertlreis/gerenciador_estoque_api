<?php

namespace App\Support\Logging;

use App\Support\Auditoria\AuditoriaRedactor;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class SierraLog
{
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
    private const DEFAULT_EVENT = 'system.log.unclassified';

    /**
     * @param array<string, mixed> $context
     */
    public static function debug(string $eventName, array $context = []): void
    {
        self::event('debug', $eventName, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $eventName, array $context = []): void
    {
        self::event('info', $eventName, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $eventName, array $context = []): void
    {
        self::event('warning', $eventName, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $eventName, array $context = []): void
    {
        self::event('error', $eventName, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function http(string $eventName, array $context = [], string $level = 'info'): void
    {
        self::event($level, $eventName, ['event_domain' => 'http'] + $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function auth(string $eventName, array $context = [], string $level = 'info'): void
    {
        self::event($level, $eventName, ['event_domain' => 'auth'] + $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function browser(string $eventName, array $context = [], string $level = 'warning'): void
    {
        self::event($level, $eventName, ['event_domain' => 'browser'] + $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function integration(string $eventName, array $context = [], string $level = 'info'): void
    {
        self::event($level, $eventName, ['event_domain' => 'integration'] + $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function inventory(string $eventName, array $context = [], string $level = 'info'): void
    {
        self::event($level, $eventName, ['event_domain' => 'inventory'] + $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function finance(string $eventName, array $context = [], string $level = 'info'): void
    {
        self::event($level, $eventName, ['event_domain' => 'finance'] + $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function audit(string $eventName, array $context = [], string $level = 'info'): void
    {
        self::event($level, $eventName, ['event_domain' => 'audit'] + $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function job(string $eventName, array $context = [], string $level = 'info'): void
    {
        self::event($level, $eventName, ['event_domain' => 'job'] + $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function system(string $eventName, array $context = [], string $level = 'info'): void
    {
        self::event($level, $eventName, ['event_domain' => 'system'] + $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function event(string $level, string $eventName, array $context = []): void
    {
        $app = Facade::getFacadeApplication();
        if ($app === null || !$app->bound('log')) {
            return;
        }

        $eventName = self::normalizeEventName($eventName);
        $level = self::normalizeLevel($level);
        $context = self::normalizeContext($eventName, $context);

        try {
            Log::channel('stderr')->log($level, $eventName, $context);
        } catch (Throwable) {
            // Observability must never break the business flow.
        }

    }

    public static function normalizeEventName(string $eventName): string
    {
        $eventName = Str::of($eventName)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9.]+/', '_')
            ->replaceMatches('/_{2,}/', '_')
            ->replaceMatches('/\.{2,}/', '.')
            ->trim('._')
            ->toString();

        if ($eventName === '' || !str_contains($eventName, '.')) {
            return self::DEFAULT_EVENT;
        }

        return $eventName;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function normalizeContext(string $eventName, array $context): array
    {
        $context = self::withExceptionFields($context);
        $context = self::withRequestContext($context);
        $domain = (string) ($context['event_domain'] ?? Str::before($eventName, '.'));
        $app = Facade::getFacadeApplication();
        $hasConfig = $app !== null && $app->bound('config');

        $base = [
            'event_name' => $eventName,
            'event_domain' => self::normalizeToken($domain, 'system'),
            'app' => $hasConfig ? config('app.name') : 'Laravel',
            'env' => $hasConfig ? config('app.env') : (env('APP_ENV') ?: 'production'),
            'service' => env('APP_SERVICE', 'gerenciador-estoque-api'),
        ];

        return AuditoriaRedactor::redact($base + $context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function withExceptionFields(array $context): array
    {
        $exception = $context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            $context += [
                'exception_class' => get_class($exception),
                'exception_code' => $exception->getCode(),
                'exception_message' => $exception->getMessage(),
            ];
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function withRequestContext(array $context): array
    {
        try {
            if (!app()->bound('request')) {
                return $context;
            }

            $request = request();
            $context += [
                'request_id' => $request->attributes->get('request_id') ?: $request->headers->get('X-Request-Id'),
                'method' => $request->method(),
                'route' => $request->path(),
                'route_name' => $request->route()?->getName(),
                'user_id' => $request->user()?->id,
            ];
        } catch (Throwable) {
            return $context;
        }

        return array_filter($context, static fn ($value) => $value !== null && $value !== '');
    }

    private static function normalizeLevel(string $level): string
    {
        $level = strtolower($level);

        return in_array($level, self::LEVELS, true) ? $level : 'info';
    }

    private static function normalizeToken(string $value, string $fallback): string
    {
        $value = Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->replaceMatches('/_{2,}/', '_')
            ->trim('_')
            ->toString();

        return $value !== '' ? $value : $fallback;
    }
}
