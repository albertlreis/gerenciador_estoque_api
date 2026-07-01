<?php

namespace Tests\Feature;

use Tests\TestCase;

class RequestContextMiddlewareTest extends TestCase
{
    public function test_health_response_preserves_request_id_header(): void
    {
        $this->getJson('/api/v1/health', ['X-Request-Id' => 'test-request-123'])
            ->assertOk()
            ->assertHeader('X-Request-Id', 'test-request-123');
    }
}
