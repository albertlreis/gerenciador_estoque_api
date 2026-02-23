<?php

namespace App\Support\Audit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditContext
{
    private ?string $requestId = null;
    private ?string $route = null;
    private ?string $method = null;
    private ?string $ip = null;
    private ?string $userAgent = null;
    private string $origin = 'API';

    /** @var array{type:string,id:int|string|null,name:string|null}|null */
    private ?array $actor = null;

    public function initializeFromRequest(Request $request): void
    {
        $this->requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $this->route = '/' . ltrim((string) $request->path(), '/');
        $this->method = strtoupper((string) $request->method());
        $this->ip = $request->ip();
        $this->userAgent = $request->userAgent();
        $this->origin = 'API';
    }

    public function ensureRequestId(): string
    {
        if (!$this->requestId) {
            $this->requestId = (string) Str::uuid();
        }

        return $this->requestId;
    }

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function getRequestId(): string
    {
        return $this->ensureRequestId();
    }

    public function setActor(?Authenticatable $user, string $actorType = 'USER'): void
    {
        if (!$user) {
            $this->actor = null;
            return;
        }

        $this->actor = [
            'type' => strtoupper($actorType),
            'id' => $user->getAuthIdentifier(),
            'name' => $user->nome ?? $user->name ?? $user->email ?? null,
        ];
    }

    /** @return array{type:string,id:int|string|null,name:string|null}|null */
    public function getActor(): ?array
    {
        return $this->actor;
    }

    public function setOrigin(string $origin): void
    {
        $this->origin = strtoupper($origin);
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }
}
