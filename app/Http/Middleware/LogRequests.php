<?php

namespace App\Http\Middleware;

use App\Support\Logging\SierraLog;
use Closure;
use Illuminate\Http\UploadedFile;

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

            SierraLog::http('http.write_request_payload', [
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
