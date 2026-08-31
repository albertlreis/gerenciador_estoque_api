<?php

namespace App\Http\Middleware;

use App\Support\Logging\SierraLog;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AddRequestContext
{
    public function handle($request, Closure $next)
    {
        $requestId = $this->requestId($request->headers->get('X-Request-Id'));
        $request->headers->set('X-Request-Id', $requestId);
        $request->attributes->set('request_id', $requestId);

        $startedAt = microtime(true);
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $baseContext = $this->baseContext($request, $requestId);
        Log::withContext($baseContext);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $context = array_merge($baseContext, $this->databaseMetrics($connection), [
                'status' => 500,
                'duration_ms' => $this->durationMs($startedAt),
                'response_bytes' => 0,
                'memory_peak_bytes' => memory_get_peak_usage(true),
                'user_id' => $request->user()?->id,
                'exception' => $exception,
            ]);

            Log::withContext($context);
            SierraLog::http('http.request_failed', $context, 'error');
            $connection->disableQueryLog();
            $connection->flushQueryLog();

            throw $exception;
        }

        $requestId = (string) $request->attributes->get('request_id', $requestId);
        $baseContext['request_id'] = $requestId;
        $baseContext['route'] = $request->route()?->uri() ?: $baseContext['route'];

        if (isset($response->headers)) {
            $response->headers->set('X-Request-Id', $requestId);
        }

        $context = array_merge($baseContext, $this->databaseMetrics($connection), [
            'status' => $this->statusCode($response),
            'duration_ms' => $this->durationMs($startedAt),
            'response_bytes' => $this->responseBytes($response),
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'user_id' => $request->user()?->id,
            'route_name' => $request->route()?->getName(),
        ]);

        Log::withContext($context);

        if ($this->shouldLog($request)) {
            SierraLog::http('http.request', $context, $this->levelFor($context['status']));
        }

        $connection->disableQueryLog();
        $connection->flushQueryLog();

        return $response;
    }

    private function requestId(?string $header): string
    {
        $value = trim((string) $header);

        if ($value !== '' && preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $value)) {
            return $value;
        }

        return (string) Str::uuid();
    }

    private function baseContext($request, string $requestId): array
    {
        return [
            'app' => config('app.name'),
            'env' => config('app.env'),
            'service' => env('APP_SERVICE', 'gerenciador-estoque-api'),
            'request_id' => $requestId,
            'method' => $request->method(),
            'route' => $request->route()?->uri() ?: $request->path(),
            'user_id' => $request->user()?->id,
        ];
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function statusCode($response): int
    {
        if (method_exists($response, 'getStatusCode')) {
            return (int) $response->getStatusCode();
        }

        if (method_exists($response, 'status')) {
            return (int) $response->status();
        }

        return 200;
    }

    private function databaseMetrics($connection): array
    {
        $queries = $connection->getQueryLog();

        return [
            'db_query_count' => count($queries),
            'db_duration_ms' => (int) round(array_sum(array_column($queries, 'time'))),
        ];
    }

    private function responseBytes($response): ?int
    {
        $header = isset($response->headers) ? $response->headers->get('Content-Length') : null;
        if (is_numeric($header)) {
            return (int) $header;
        }

        if (method_exists($response, 'getContent')) {
            $content = $response->getContent();
            return is_string($content) ? strlen($content) : null;
        }

        return null;
    }

    private function shouldLog($request): bool
    {
        return $request->method() !== 'OPTIONS'
            && !str_ends_with((string) $request->path(), '/health')
            && (string) $request->path() !== 'api/v1/health';
    }

    private function levelFor(int $status): string
    {
        return match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default => 'info',
        };
    }
}
