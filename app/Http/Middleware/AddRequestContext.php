<?php

namespace App\Http\Middleware;

use App\Support\Logging\SierraLog;
use Closure;
use Illuminate\Support\Facades\Log;
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
        $baseContext = $this->baseContext($request, $requestId);
        Log::withContext($baseContext);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $context = array_merge($baseContext, [
                'status' => 500,
                'duration_ms' => $this->durationMs($startedAt),
                'user_id' => $request->user()?->id,
                'exception' => $exception,
            ]);

            Log::withContext($context);
            SierraLog::http('http.request_failed', $context, 'error');

            throw $exception;
        }

        $requestId = (string) $request->attributes->get('request_id', $requestId);
        $baseContext['request_id'] = $requestId;

        if (isset($response->headers)) {
            $response->headers->set('X-Request-Id', $requestId);
        }

        $context = array_merge($baseContext, [
            'status' => $this->statusCode($response),
            'duration_ms' => $this->durationMs($startedAt),
            'user_id' => $request->user()?->id,
            'route_name' => $request->route()?->getName(),
        ]);

        Log::withContext($context);

        if ($this->shouldLog($request)) {
            SierraLog::http('http.request', $context, $this->levelFor($context['status']));
        }

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
            'route' => $request->path(),
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
