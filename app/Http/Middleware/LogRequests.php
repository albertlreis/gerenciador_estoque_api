<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class LogRequests
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if (in_array($request->method(), ['POST', 'PUT', 'DELETE'])) {
            $safePayload = [];

            foreach ($request->input() as $key => $value) {
                if (is_array($value)) {
                    $safePayload[$key] = array_map(function ($item) {
                        return $item instanceof UploadedFile ? '[uploaded file]' : $item;
                    }, $value);
                } else {
                    $safePayload[$key] = $value instanceof UploadedFile ? '[uploaded file]' : $value;
                }
            }

            Log::channel('estoque')->info('Requisicao API', [
                'user' => auth()->user()?->email,
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'payload' => $safePayload,
                'status' => method_exists($response, 'status')
                    ? $response->status()
                    : (method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null),
            ]);
        }

        return $response;
    }
}