<?php

namespace App\Http\Middleware;

use App\Support\Audit\AuditContext;
use Closure;
use Illuminate\Http\Request;

class AuditContextMiddleware
{
    public function __construct(private readonly AuditContext $context) {}

    public function handle(Request $request, Closure $next)
    {
        $this->context->initializeFromRequest($request);

        if ($request->user()) {
            $this->context->setActor($request->user(), 'USER');
        }

        $response = $next($request);

        if (method_exists($response, 'headers')) {
            $response->headers->set('X-Request-Id', $this->context->getRequestId());
        }

        return $response;
    }
}
