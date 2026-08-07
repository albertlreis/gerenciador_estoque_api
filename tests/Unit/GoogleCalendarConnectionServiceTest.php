<?php

namespace Tests\Unit;

use App\Integrations\GoogleCalendar\Auth\GoogleCalendarOAuthService;
use App\Integrations\GoogleCalendar\Clients\GoogleCalendarClient;
use App\Integrations\GoogleCalendar\Models\GoogleCalendarConexao;
use App\Integrations\GoogleCalendar\Models\GoogleCalendarToken;
use App\Integrations\GoogleCalendar\Services\GoogleCalendarConnectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Mockery;

class GoogleCalendarConnectionServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_healthcheck_uses_new_access_token_after_reconnecting_existing_connection(): void
    {
        $conexao = GoogleCalendarConexao::create(['status' => 'ativa']);
        GoogleCalendarToken::create([
            'conexao_id' => $conexao->id,
            'access_token' => 'old-access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);
        $conexao->load('token');

        $client = Mockery::mock(GoogleCalendarClient::class);
        $client->shouldReceive('get')
            ->once()
            ->with('/users/me/calendarList', 'new-access-token', ['maxResults' => 1])
            ->andReturn(['status' => 200, 'body' => null, 'json' => ['items' => []]]);

        $service = new GoogleCalendarConnectionService(
            config('google_calendar'),
            Mockery::mock(GoogleCalendarOAuthService::class),
            $client
        );

        $service->persistTokensFromOAuth($conexao, [
            'access_token' => 'new-access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
        ]);

        $this->assertTrue($service->healthcheck($conexao));
    }
}
