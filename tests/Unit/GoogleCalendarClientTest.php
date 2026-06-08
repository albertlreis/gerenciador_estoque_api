<?php

namespace Tests\Unit;

use App\Integrations\GoogleCalendar\Clients\GoogleCalendarClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class GoogleCalendarClientTest extends TestCase
{
    public function test_retries_500_response_when_http_errors_are_disabled(): void
    {
        $mock = new MockHandler([
            new Response(500, ['Content-Type' => 'application/json'], '{"error":{"message":"temporario"}}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'),
        ]);

        $client = new GoogleCalendarClient([
            'base_url' => 'https://example.com/calendar/v3',
            'retry' => ['times' => 2, 'sleep_ms' => 0],
        ], new Client([
            'base_uri' => 'https://example.com/calendar/v3/',
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]));

        $res = $client->get('/users/me/calendarList', 'token-test');

        $this->assertSame(200, $res['status']);
        $this->assertSame(true, $res['json']['ok'] ?? null);
        $this->assertSame(0, $mock->count());
    }

    public function test_retries_429_response_when_http_errors_are_disabled(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Content-Type' => 'application/json'], '{"error":{"message":"rate limit"}}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'),
        ]);

        $client = new GoogleCalendarClient([
            'base_url' => 'https://example.com/calendar/v3',
            'retry' => ['times' => 2, 'sleep_ms' => 0],
        ], new Client([
            'base_uri' => 'https://example.com/calendar/v3/',
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]));

        $res = $client->get('/users/me/calendarList', 'token-test');

        $this->assertSame(200, $res['status']);
        $this->assertSame(true, $res['json']['ok'] ?? null);
        $this->assertSame(0, $mock->count());
    }
}
