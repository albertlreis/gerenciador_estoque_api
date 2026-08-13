<?php

namespace Tests\Unit;

use App\Integrations\GoogleCalendar\Auth\GoogleCalendarOAuthService;
use App\Integrations\GoogleCalendar\Clients\GoogleCalendarClient;
use App\Integrations\GoogleCalendar\Models\GoogleCalendarConexao;
use App\Integrations\GoogleCalendar\Models\GoogleCalendarToken;
use App\Integrations\GoogleCalendar\Models\GoogleCalendarCalendar;
use App\Integrations\GoogleCalendar\Exceptions\GoogleCalendarException;
use App\Integrations\GoogleCalendar\Services\GoogleCalendarConnectionService;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Mockery;

class GoogleCalendarConnectionServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_healthcheck_uses_new_access_token_after_reconnecting_existing_connection(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Connection Service',
            'email' => 'connection-service-' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);
        $conexao = GoogleCalendarConexao::create(['usuario_id' => $usuario->id, 'status' => 'ativa']);
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

    public function test_failed_reconnection_preserves_previous_tokens_and_calendars(): void
    {
        $usuario = $this->createUser('failed-reconnect');
        $conexao = GoogleCalendarConexao::create(['usuario_id' => $usuario->id, 'status' => 'ativa']);
        GoogleCalendarToken::create([
            'conexao_id' => $conexao->id,
            'access_token' => 'old-access-token',
            'refresh_token' => 'old-refresh-token',
            'expires_at' => now()->addHour(),
        ]);
        GoogleCalendarCalendar::create([
            'conexao_id' => $conexao->id,
            'calendar_id' => 'old-calendar',
            'summary' => 'Agenda anterior',
            'enabled' => true,
        ]);

        $client = Mockery::mock(GoogleCalendarClient::class);
        $client->shouldReceive('get')->once()->andReturn(['status' => 503, 'body' => null, 'json' => null]);
        $service = new GoogleCalendarConnectionService(config('google_calendar'), Mockery::mock(GoogleCalendarOAuthService::class), $client);

        try {
            $service->completeOAuthForUser($usuario->id, [
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
            ]);
            $this->fail('A reconexao deveria falhar antes de substituir os dados locais.');
        } catch (GoogleCalendarException $e) {
            $this->assertSame('calendar_list_failed', $e->reason);
        }

        $this->assertSame('old-access-token', GoogleCalendarToken::where('conexao_id', $conexao->id)->first()->access_token);
        $this->assertDatabaseHas('google_calendar_calendars', [
            'conexao_id' => $conexao->id,
            'calendar_id' => 'old-calendar',
            'enabled' => true,
        ]);
    }

    public function test_oauth_connection_requires_refresh_token_before_changing_local_data(): void
    {
        $usuario = $this->createUser('missing-refresh');
        $service = new GoogleCalendarConnectionService(
            config('google_calendar'),
            Mockery::mock(GoogleCalendarOAuthService::class),
            Mockery::mock(GoogleCalendarClient::class)
        );

        $this->expectException(GoogleCalendarException::class);
        try {
            $service->completeOAuthForUser($usuario->id, ['access_token' => 'access-without-refresh']);
        } finally {
            $this->assertDatabaseMissing('google_calendar_conexoes', ['usuario_id' => $usuario->id]);
        }
    }

    public function test_successful_reconnection_replaces_only_users_data_and_disables_all_calendars(): void
    {
        $usuario = $this->createUser('success-reconnect');
        $outroUsuario = $this->createUser('other-user');
        $outraConexao = GoogleCalendarConexao::create(['usuario_id' => $outroUsuario->id, 'status' => 'ativa']);
        GoogleCalendarToken::create([
            'conexao_id' => $outraConexao->id,
            'access_token' => 'other-access-token',
            'refresh_token' => 'other-refresh-token',
            'expires_at' => now()->addHour(),
        ]);
        GoogleCalendarCalendar::create([
            'conexao_id' => $outraConexao->id,
            'calendar_id' => 'shared-calendar-id',
            'summary' => 'Agenda do outro usuario',
            'enabled' => true,
        ]);

        $client = Mockery::mock(GoogleCalendarClient::class);
        $client->shouldReceive('get')->once()->with('/users/me/calendarList', 'new-access-token', [
            'maxResults' => 250,
            'minAccessRole' => 'reader',
        ])->andReturn([
            'status' => 200,
            'body' => null,
            'json' => ['items' => [[
                'id' => 'shared-calendar-id',
                'summary' => 'Minha agenda',
                'primary' => true,
                'accessRole' => 'owner',
            ]]],
        ]);
        $service = new GoogleCalendarConnectionService(config('google_calendar'), Mockery::mock(GoogleCalendarOAuthService::class), $client);

        $conexao = $service->completeOAuthForUser($usuario->id, [
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
        ]);

        $this->assertDatabaseHas('google_calendar_calendars', [
            'conexao_id' => $conexao->id,
            'calendar_id' => 'shared-calendar-id',
            'enabled' => false,
        ]);
        $this->assertDatabaseHas('google_calendar_calendars', [
            'conexao_id' => $outraConexao->id,
            'calendar_id' => 'shared-calendar-id',
            'enabled' => true,
        ]);
        $this->assertSame('other-access-token', GoogleCalendarToken::where('conexao_id', $outraConexao->id)->first()->access_token);
    }

    private function createUser(string $prefix): Usuario
    {
        return Usuario::create([
            'nome' => 'Usuario Connection Service',
            'email' => $prefix . '-' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);
    }
}
