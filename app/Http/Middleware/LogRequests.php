<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;

class LogRequests
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if (in_array($request->method(), ['POST', 'PUT', 'DELETE'])) {
            Log::channel('estoque')->info('Requisição API', [
                'user' => auth()->user()?->email,
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'payload' => $request->all(),
                'status' => $response->status(),
            ]);
        }

        return $response;
    }
}
