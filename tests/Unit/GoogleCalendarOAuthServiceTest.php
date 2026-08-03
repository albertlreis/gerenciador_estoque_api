<?php

namespace Tests\Unit;

use App\Integrations\GoogleCalendar\Auth\GoogleCalendarOAuthService;
use App\Integrations\GoogleCalendar\Exceptions\GoogleCalendarException;
use PHPUnit\Framework\TestCase;

class GoogleCalendarOAuthServiceTest extends TestCase
{
    public function test_build_authorization_url_uses_offline_access_and_calendar_scopes(): void
    {
        $service = new GoogleCalendarOAuthService([
            'auth_url' => 'https://accounts.google.com',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'redirect_uri' => 'https://app.test/api/v1/integrations/google-calendar/callback',
            'scope' => 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.calendarlist.readonly',
        ]);

        $url = $service->buildAuthorizationUrl('state-token');
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('true', $query['include_granted_scopes']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertStringContainsString('calendar.events', $query['scope']);
        $this->assertStringContainsString('calendar.calendarlist.readonly', $query['scope']);
        $this->assertSame('state-token', $query['state']);
    }

    public function test_build_authorization_url_reports_missing_oauth_config_without_secrets(): void
    {
        $service = new GoogleCalendarOAuthService([
            'auth_url' => 'https://accounts.google.com',
            'client_id' => '',
            'client_secret' => '',
            'redirect_uri' => '',
        ]);

        try {
            $service->buildAuthorizationUrl('state-token');
            $this->fail('Expected GoogleCalendarException.');
        } catch (GoogleCalendarException $e) {
            $this->assertSame('config_invalida', $e->reason);
            $this->assertSame([
                'GOOGLE_CALENDAR_CLIENT_ID',
                'GOOGLE_CALENDAR_CLIENT_SECRET',
                'GOOGLE_CALENDAR_REDIRECT_URI',
            ], $e->context['missing_config']);
            $this->assertStringNotContainsString('client-secret', $e->getMessage());
        }
    }
}
