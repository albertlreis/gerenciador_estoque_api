<?php

namespace App\Http\Middleware;

use App\Support\Audit\AuditRequestContext;
use Closure;
use Illuminate\Http\Request;

class CaptureAuditContext
{
    public function handle(Request $request, Closure $next)
    {
        app()->instance(AuditRequestContext::class, AuditRequestContext::fromRequest($request));

        return $next($request);
    }
}
