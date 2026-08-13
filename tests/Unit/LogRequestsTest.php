<?php

namespace Tests\Unit;

use App\Http\Middleware\LogRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class LogRequestsTest extends TestCase
{
    public function test_google_calendar_write_log_omits_payload_email_and_concrete_identifiers(): void
    {
        $request = Request::create(
            '/api/v1/integrations/google-calendar/events/event-secret?calendar_id=private@example.com',
            'DELETE',
            [
                'calendar_id' => 'private@example.com',
                'summary' => 'Titulo privado',
                'attendees' => ['guest@example.com'],
            ]
        );
        $route = new Route(
            ['DELETE'],
            'api/v1/integrations/google-calendar/events/{eventId}',
            static fn () => null
        );
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);

        $logged = null;
        $logger = Mockery::mock();
        $logger->shouldReceive('log')
            ->once()
            ->andReturnUsing(function (string $level, string $event, array $context) use (&$logged): void {
                $logged = compact('level', 'event', 'context');
            });
        Log::shouldReceive('channel')->once()->with('stderr')->andReturn($logger);

        (new LogRequests())->handle($request, static fn () => response()->json(['ok' => true]));

        $this->assertSame('info', $logged['level']);
        $this->assertSame('http.write_request_payload', $logged['event']);
        $this->assertSame([], $logged['context']['payload']);
        $this->assertNull($logged['context']['user'] ?? null);
        $this->assertSame(
            'api/v1/integrations/google-calendar/events/{eventId}',
            $logged['context']['uri']
        );
        $serialized = json_encode($logged['context']);
        $this->assertStringNotContainsString('Titulo privado', $serialized);
        $this->assertStringNotContainsString('private@example.com', $serialized);
        $this->assertStringNotContainsString('guest@example.com', $serialized);
    }
}
