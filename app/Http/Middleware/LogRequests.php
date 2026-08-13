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
            $isGoogleCalendar = $request->is('api/v1/integrations/google-calendar/*')
                || $request->is('v1/integrations/google-calendar/*');
            $safePayload = [];

            if (!$isGoogleCalendar) {
                foreach ($request->input() as $key => $value) {
                    if (is_array($value)) {
                        $safePayload[$key] = array_map(function ($item) {
                            return $item instanceof UploadedFile ? '[uploaded file]' : $item;
                        }, $value);
                    } else {
                        $safePayload[$key] = $value instanceof UploadedFile ? '[uploaded file]' : $value;
                    }
                }
            }

            SierraLog::http('http.write_request_payload', [
                'user' => $isGoogleCalendar ? null : auth()->user()?->email,
                'method' => $request->method(),
                'uri' => $isGoogleCalendar
                    ? ($request->route()?->uri() ?? 'api/v1/integrations/google-calendar')
                    : $request->getRequestUri(),
                'payload' => $safePayload,
                'status' => method_exists($response, 'status')
                    ? $response->status()
                    : (method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null),
            ]);
        }

        return $response;
    }
}
