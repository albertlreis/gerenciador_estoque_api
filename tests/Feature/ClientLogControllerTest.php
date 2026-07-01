<?php

namespace Tests\Feature;

use App\Services\AuditoriaLogService;
use Mockery;
use Tests\TestCase;

class ClientLogControllerTest extends TestCase
{
    public function test_accepts_handled_front_logs_with_standard_fields(): void
    {
        $this->mock(AuditoriaLogService::class, function ($mock): void {
            $mock->shouldReceive('registrar')
                ->once()
                ->with(Mockery::on(function (array $payload): bool {
                    return $payload['nivel'] === 'error'
                        && $payload['modulo'] === 'catalog'
                        && $payload['acao'] === 'load_products'
                        && $payload['status'] === 'handled_error'
                        && $payload['source_system'] === 'front'
                        && $payload['source_kind'] === 'browser_error';
                }))
                ->andReturn(null);
        });

        $response = $this->postJson('/api/v1/client-logs', [
            'event_name' => 'browser.catalog.load_failed',
            'event_type' => 'handled_error',
            'level' => 'error',
            'module' => 'catalog',
            'operation' => 'load_products',
            'message' => 'Falha ao carregar catalogo token=abc123',
            'route' => '/catalogo',
            'request_id' => 'front-request-123',
            'context' => [
                'password' => 'secret',
            ],
        ]);

        $response
            ->assertAccepted()
            ->assertHeader('X-Request-Id', 'front-request-123')
            ->assertJsonPath('request_id', 'front-request-123');
    }
}
