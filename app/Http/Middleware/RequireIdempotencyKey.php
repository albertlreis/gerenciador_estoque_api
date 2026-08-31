<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RequireIdempotencyKey
{
    public function handle(Request $request, Closure $next)
    {
        $key = trim((string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key')));
        if ($key === '' || !preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $key)) {
            return response()->json(['message' => 'Informe uma chave de idempotencia valida.'], 422);
        }

        $scope = implode('|', [(string) $request->user()->id, $request->method(), $request->route()?->uri(), $key]);
        $cacheKey = 'idempotency:response:' . hash('sha256', $scope);
        $requestHash = hash('sha256', json_encode($request->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Cache::lock($cacheKey . ':lock', 210)->block(30, function () use ($request, $next, $cacheKey, $requestHash) {
            $stored = Cache::get($cacheKey);
            if (is_array($stored)) {
                if (!hash_equals((string) $stored['request_hash'], $requestHash)) {
                    return response()->json(['message' => 'A chave de idempotencia ja foi usada com outro conteudo.'], 409);
                }

                return response($stored['content'], $stored['status'], $stored['headers']);
            }

            $response = $next($request);
            $content = method_exists($response, 'getContent') ? $response->getContent() : null;
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300 && is_string($content)) {
                Cache::put($cacheKey, [
                    'request_hash' => $requestHash,
                    'status' => $response->getStatusCode(),
                    'content' => $content,
                    'headers' => array_filter([
                        'Content-Type' => $response->headers->get('Content-Type'),
                        'Content-Disposition' => $response->headers->get('Content-Disposition'),
                    ]),
                ], now()->addDay());
            }

            return $response;
        });
    }
}
