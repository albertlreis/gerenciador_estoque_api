<?php

namespace Tests\Unit;

use App\Integrations\GoogleCalendar\Auth\GoogleCalendarOAuthService;
use App\Integrations\GoogleCalendar\Clients\GoogleCalendarClient;
use App\Integrations\GoogleCalendar\Services\GoogleCalendarConnectionService;
use App\Integrations\GoogleCalendar\Services\GoogleCalendarEventService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class GoogleCalendarEventServiceTest extends TestCase
{
    public function test_event_body_maps_meet_attendees_and_rfc3339_dates(): void
    {
        $service = $this->service();
        $method = (new ReflectionClass($service))->getMethod('eventBody');
        $method->setAccessible(true);

        $body = $method->invoke($service, [
            'summary' => 'Reuniao comercial',
            'description' => 'Briefing',
            'location' => 'Loja',
            'start' => '2026-05-13T10:00:00-03:00',
            'end' => '2026-05-13T11:00:00-03:00',
            'timezone' => 'America/Sao_Paulo',
            'generate_meet' => true,
            'attendees' => [
                ['email' => 'cliente@example.test'],
                ['email' => 'cliente@example.test'],
                ['email' => 'invalido'],
            ],
        ], true);

        $this->assertSame('Reuniao comercial', $body['summary']);
        $this->assertSame('Loja', $body['location']);
        $this->assertSame('2026-05-13T10:00:00-03:00', $body['start']['dateTime']);
        $this->assertSame('America/Sao_Paulo', $body['start']['timeZone']);
        $this->assertSame([['email' => 'cliente@example.test']], $body['attendees']);
        $this->assertSame('hangoutsMeet', $body['conferenceData']['createRequest']['conferenceSolutionKey']['type']);
        $this->assertStringStartsWith('sierra-', $body['conferenceData']['createRequest']['requestId']);
    }

    public function test_mutation_query_notifies_guests_by_default(): void
    {
        $service = $this->service();
        $method = (new ReflectionClass($service))->getMethod('mutationQuery');
        $method->setAccessible(true);

        $this->assertSame(['sendUpdates' => 'all'], $method->invoke($service, []));
        $this->assertSame(['sendUpdates' => 'none'], $method->invoke($service, ['send_updates' => false]));
    }

    public function test_all_day_event_uses_exclusive_end_date(): void
    {
        $service = $this->service();
        $method = (new ReflectionClass($service))->getMethod('eventBody');
        $method->setAccessible(true);

        $body = $method->invoke($service, [
            'summary' => 'Dia inteiro',
            'start' => '2026-05-13',
            'end' => '2026-05-13',
            'all_day' => true,
        ], true);

        $this->assertSame('2026-05-13', $body['start']['date']);
        $this->assertSame('2026-05-14', $body['end']['date']);
    }

    private function service(): GoogleCalendarEventService
    {
        $config = ['timezone' => 'America/Sao_Paulo'];
        $oauth = new GoogleCalendarOAuthService($config);
        $client = new GoogleCalendarClient($config);
        $connections = new GoogleCalendarConnectionService($config, $oauth, $client);

        return new GoogleCalendarEventService($config, $connections, $client);
    }
}
