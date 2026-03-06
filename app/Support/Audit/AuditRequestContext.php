<?php

namespace App\Support\Audit;

use Illuminate\Http\Request;

class AuditRequestContext
{
    public function __construct(
        public readonly ?string $route,
        public readonly ?string $method,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $route = null;

        if ($request->route()) {
            $route = $request->route()->getName() ?: $request->route()->uri();
        }

        return new self(
            route: $route,
            method: $request->method(),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }
}
