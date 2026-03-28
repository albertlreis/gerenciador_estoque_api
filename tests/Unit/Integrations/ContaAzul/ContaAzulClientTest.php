<?php

namespace Tests\Unit\Integrations\ContaAzul;

use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ContaAzulClientTest extends TestCase
{
    public function test_get_returns_status_and_json_without_throwing_on_4xx(): void
    {
        $mock = new MockHandler([
            new Response(422, ['Content-Type' => 'application/json'], '{"erro":"validacao"}'),
        ]);
        $client = new Client([
            'base_uri' => 'https://example.com/',
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]);

        $ca = new ContaAzulClient([
            'base_url' => 'https://example.com',
            'timeout' => 5,
            'connect_timeout' => 5,
            'retry' => ['times' => 1, 'sleep_ms' => 0],
        ], $client);

        $res = $ca->get('v1/test', 'token-test');

        $this->assertSame(422, $res['status']);
        $this->assertIsArray($res['json']);
        $this->assertSame('validacao', $res['json']['erro'] ?? null);
    }
}
