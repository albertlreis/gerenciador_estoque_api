<?php

namespace Tests\Feature\Integrations;

use App\Models\AuditoriaLog;
use App\Models\Usuario;
use App\Integrations\GoogleCalendar\Auth\GoogleCalendarOAuthService;
use App\Integrations\GoogleCalendar\Clients\GoogleCalendarClient;
use App\Integrations\GoogleCalendar\Exceptions\GoogleCalendarException;
use App\Integrations\GoogleCalendar\Models\GoogleCalendarCalendar;
use App\Integrations\GoogleCalendar\Models\GoogleCalendarConexao;
use App\Integrations\GoogleCalendar\Models\GoogleCalendarToken;
use App\Integrations\GoogleCalendar\Services\GoogleCalendarConnectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class GoogleCalendarEndpointsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_oauth_authorize_reports_missing_configuration_fields(): void
    {
        $this->actingUser(['google_calendar.configurar']);
        config()->set('google_calendar.client_id', '');
        config()->set('google_calendar.client_secret', '');
        config()->set('google_calendar.redirect_uri', '');

        $response = $this->getJson('/api/v1/integrations/google-calendar/oauth/authorize');

        $response->assertStatus(422);
        $response->assertJson([
            'ok' => false,
            'reason' => 'config_invalida',
            'missing_config' => [
                'GOOGLE_CALENDAR_CLIENT_ID',
                'GOOGLE_CALENDAR_CLIENT_SECRET',
                'GOOGLE_CALENDAR_REDIRECT_URI',
            ],
        ]);
    }

    public function test_oauth_callback_preserves_safe_token_error_reason_without_exposing_details(): void
    {
        $usuario = $this->createUser();
        $this->grantDatabasePermission($usuario, 'google_calendar.configurar');
        config()->set('google_calendar.oauth_front_redirect', 'http://front.test/integracoes/google-agenda');
        $this->app->instance(GoogleCalendarOAuthService::class, Mockery::mock(GoogleCalendarOAuthService::class, function ($mock) {
            $mock->shouldReceive('exchangeCodeForToken')
                ->once()
                ->andThrow(new GoogleCalendarException('invalid_grant: secret details', 'oauth_token_error'));
        }));

        Cache::put('google_calendar_oauth:state-token-error', ['user_id' => $usuario->id], now()->addMinutes(10));

        $response = $this->get('/api/v1/integrations/google-calendar/callback?state=state-token-error&code=code');

        $response->assertRedirect();
        $this->assertStringContainsString('gc=erro', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('reason=oauth_token_error', (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString('secret', (string) $response->headers->get('Location'));
    }

    public function test_oauth_callback_rejects_invalid_state_with_safe_reason(): void
    {
        config()->set('google_calendar.oauth_front_redirect', 'http://front.test/integracoes/google-agenda');

        $response = $this->get('/api/v1/integrations/google-calendar/callback?state=unknown&code=code');

        $response->assertRedirect();
        $this->assertStringContainsString('reason=state_invalido', (string) $response->headers->get('Location'));
    }

    public function test_oauth_states_are_independent_per_user_and_single_use(): void
    {
        config()->set('google_calendar.client_id', 'client-id.test');
        config()->set('google_calendar.client_secret', 'client-secret.test');
        config()->set('google_calendar.redirect_uri', 'https://api.test/callback');
        config()->set('google_calendar.oauth_front_redirect', 'http://front.test/integracoes/google-agenda');

        $primeiro = $this->actingUser(['google_calendar.configurar']);
        $primeiraUrl = $this->getJson('/api/v1/integrations/google-calendar/oauth/authorize')->json('url');
        parse_str((string) parse_url($primeiraUrl, PHP_URL_QUERY), $primeiraQuery);

        $segundo = $this->actingUser(['google_calendar.configurar']);
        $segundaUrl = $this->getJson('/api/v1/integrations/google-calendar/oauth/authorize')->json('url');
        parse_str((string) parse_url($segundaUrl, PHP_URL_QUERY), $segundaQuery);

        $this->assertNotSame($primeiraQuery['state'], $segundaQuery['state']);
        $this->assertSame($primeiro->id, Cache::get('google_calendar_oauth:' . $primeiraQuery['state'])['user_id']);
        $this->assertSame($segundo->id, Cache::get('google_calendar_oauth:' . $segundaQuery['state'])['user_id']);

        $primeiro->update(['ativo' => false]);
        $this->get('/api/v1/integrations/google-calendar/callback?state=' . $primeiraQuery['state'] . '&code=code')
            ->assertRedirect();
        $reused = $this->get('/api/v1/integrations/google-calendar/callback?state=' . $primeiraQuery['state'] . '&code=code');
        $this->assertStringContainsString('reason=state_invalido', (string) $reused->headers->get('Location'));
    }

    public function test_oauth_callback_rejects_inactive_user(): void
    {
        $usuario = $this->createUser();
        $usuario->update(['ativo' => false]);
        config()->set('google_calendar.oauth_front_redirect', 'http://front.test/integracoes/google-agenda');
        Cache::put('google_calendar_oauth:inactive-user', ['user_id' => $usuario->id], now()->addMinutes(10));

        $response = $this->get('/api/v1/integrations/google-calendar/callback?state=inactive-user&code=code');

        $response->assertRedirect();
        $this->assertStringContainsString('reason=usuario_inativo', (string) $response->headers->get('Location'));
    }

    public function test_oauth_callback_rejects_user_without_current_permission(): void
    {
        $usuario = $this->createUser();
        config()->set('google_calendar.oauth_front_redirect', 'http://front.test/integracoes/google-agenda');
        Cache::put('google_calendar_oauth:permission-removed', ['user_id' => $usuario->id], now()->addMinutes(10));

        $response = $this->get('/api/v1/integrations/google-calendar/callback?state=permission-removed&code=code');

        $response->assertRedirect();
        $this->assertStringContainsString('reason=permissao_revogada', (string) $response->headers->get('Location'));
    }

    public function test_calendar_visibility_is_persisted_and_returned(): void
    {
        $usuario = $this->actingUser(['google_calendar.configurar']);
        $conexao = GoogleCalendarConexao::create(['usuario_id' => $usuario->id, 'status' => 'ativa']);
        GoogleCalendarCalendar::create([
            'conexao_id' => $conexao->id,
            'calendar_id' => 'agenda-privada',
            'summary' => 'Agenda Privada',
            'enabled' => true,
            'access_role' => 'owner',
        ]);

        $response = $this->patchJson('/api/v1/integrations/google-calendar/calendars/agenda-privada/visibility', [
            'visibility' => 'private',
        ]);

        $response->assertOk()->assertJsonPath('data.visibility', 'private');
        $this->assertDatabaseHas('google_calendar_calendars', [
            'conexao_id' => $conexao->id,
            'calendar_id' => 'agenda-privada',
            'visibility' => 'private',
        ]);
    }

    public function test_disconnect_revokes_google_and_deletes_local_connection_data(): void
    {
        $this->clearGoogleCalendarConnections();
        $usuario = $this->actingUser(['google_calendar.configurar']);
        $conexao = GoogleCalendarConexao::create([
            'usuario_id' => $usuario->id,
            'status' => 'ativa',
            'email_externo' => 'central@example.test',
        ]);
        GoogleCalendarToken::create([
            'conexao_id' => $conexao->id,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);
        GoogleCalendarCalendar::create([
            'conexao_id' => $conexao->id,
            'calendar_id' => 'primary',
            'summary' => 'Principal',
            'enabled' => true,
        ]);

        $oauth = Mockery::mock(GoogleCalendarOAuthService::class);
        $oauth->shouldReceive('revokeToken')->once()->with('refresh-token')->andReturnTrue();
        $this->app->instance(GoogleCalendarOAuthService::class, $oauth);
        $this->app->forgetInstance(GoogleCalendarConnectionService::class);

        $response = $this->deleteJson('/api/v1/integrations/google-calendar/connection');

        $response->assertOk()->assertExactJson([
            'ok' => true,
            'local_deleted' => true,
            'google_revoked' => true,
            'manual_revoke_url' => null,
        ]);
        $this->assertDatabaseCount('google_calendar_conexoes', 0);
        $this->assertDatabaseCount('google_calendar_tokens', 0);
        $this->assertDatabaseCount('google_calendar_calendars', 0);
    }

    public function test_disconnect_deletes_local_data_when_google_is_unavailable(): void
    {
        $this->clearGoogleCalendarConnections();
        $usuario = $this->actingUser(['google_calendar.configurar']);
        $conexao = GoogleCalendarConexao::create(['usuario_id' => $usuario->id, 'status' => 'ativa']);
        GoogleCalendarToken::create([
            'conexao_id' => $conexao->id,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        $oauth = Mockery::mock(GoogleCalendarOAuthService::class);
        $oauth->shouldReceive('revokeToken')->once()->andReturnFalse();
        $this->app->instance(GoogleCalendarOAuthService::class, $oauth);
        $this->app->forgetInstance(GoogleCalendarConnectionService::class);

        $response = $this->deleteJson('/api/v1/integrations/google-calendar/connection');

        $response->assertOk()
            ->assertJsonPath('local_deleted', true)
            ->assertJsonPath('google_revoked', false)
            ->assertJsonPath('manual_revoke_url', 'https://myaccount.google.com/connections');
        $this->assertDatabaseCount('google_calendar_conexoes', 0);
        $this->assertDatabaseCount('google_calendar_tokens', 0);
    }

    public function test_disconnect_is_idempotent_without_connection(): void
    {
        $this->clearGoogleCalendarConnections();
        $this->actingUser(['google_calendar.configurar']);

        $response = $this->deleteJson('/api/v1/integrations/google-calendar/connection');

        $response->assertOk()->assertExactJson([
            'ok' => true,
            'local_deleted' => false,
            'google_revoked' => true,
            'manual_revoke_url' => null,
        ]);
    }

    public function test_disconnect_removes_only_authenticated_users_connection(): void
    {
        $this->clearGoogleCalendarConnections();
        $usuario = $this->actingUser(['google_calendar.configurar']);
        $outroUsuario = $this->createUser();
        $conexao = GoogleCalendarConexao::create(['usuario_id' => $usuario->id, 'status' => 'ativa']);
        $outraConexao = GoogleCalendarConexao::create(['usuario_id' => $outroUsuario->id, 'status' => 'ativa']);
        foreach ([$conexao, $outraConexao] as $item) {
            GoogleCalendarToken::create([
                'conexao_id' => $item->id,
                'access_token' => 'access-' . $item->id,
                'refresh_token' => 'refresh-' . $item->id,
                'expires_at' => now()->addHour(),
            ]);
        }

        $oauth = Mockery::mock(GoogleCalendarOAuthService::class);
        $oauth->shouldReceive('revokeToken')->once()->with('refresh-' . $conexao->id)->andReturnTrue();
        $this->app->instance(GoogleCalendarOAuthService::class, $oauth);
        $this->app->forgetInstance(GoogleCalendarConnectionService::class);

        $this->deleteJson('/api/v1/integrations/google-calendar/connection')->assertOk();

        $this->assertDatabaseMissing('google_calendar_conexoes', ['id' => $conexao->id]);
        $this->assertDatabaseHas('google_calendar_conexoes', [
            'id' => $outraConexao->id,
            'usuario_id' => $outroUsuario->id,
        ]);
        $this->assertDatabaseHas('google_calendar_tokens', ['conexao_id' => $outraConexao->id]);
    }

    public function test_status_and_calendar_mutation_are_isolated_by_authenticated_user(): void
    {
        $this->clearGoogleCalendarConnections();
        $usuario = $this->actingUser(['google_calendar.visualizar', 'google_calendar.configurar']);
        $outroUsuario = $this->createUser();
        $outraConexao = GoogleCalendarConexao::create(['usuario_id' => $outroUsuario->id, 'status' => 'ativa']);
        GoogleCalendarCalendar::create([
            'conexao_id' => $outraConexao->id,
            'calendar_id' => 'shared-calendar-id',
            'summary' => 'Agenda de outro usuario',
            'enabled' => true,
            'visibility' => 'private',
        ]);

        $this->getJson('/api/v1/integrations/google-calendar/status')
            ->assertOk()
            ->assertExactJson(['conectado' => false, 'conexao' => null]);

        $this->patchJson('/api/v1/integrations/google-calendar/calendars/shared-calendar-id/visibility', [
            'visibility' => 'public',
        ])->assertNotFound();

        $this->assertDatabaseHas('google_calendar_calendars', [
            'conexao_id' => $outraConexao->id,
            'calendar_id' => 'shared-calendar-id',
            'visibility' => 'private',
        ]);
    }

    public function test_disconnect_requires_configuration_permission(): void
    {
        $this->actingUser([]);

        $this->deleteJson('/api/v1/integrations/google-calendar/connection')->assertForbidden();
    }

    public function test_audit_sanitizer_removes_event_content_and_preserves_operational_metadata(): void
    {
        $log = AuditoriaLog::query()->create([
            'occurred_at' => now(),
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'nivel' => 'info',
            'modulo' => 'google_calendar',
            'acao' => 'create',
            'status' => 'sucesso',
            'message' => 'Evento Cliente Confidencial',
            'entity_type' => 'google_calendar_event',
            'entity_id' => 'event-1',
            'source_system' => 'estoque',
            'source_kind' => 'sync',
            'source_table' => 'google_calendar_logs',
            'context_json' => [
                'conexao_id' => 10,
                'calendar_id' => 'primary',
                'event_id' => 'event-1',
                'request_resumo' => '{"summary":"Cliente Confidencial"}',
                'response_resumo' => '{"description":"Segredo"}',
                'erro_mensagem' => 'Segredo',
            ],
            'metadata_json' => ['attendee' => 'pessoa@example.test'],
            'raw_excerpt' => 'Cliente Confidencial',
            'retention_days' => 90,
        ]);

        $this->artisan('google-calendar:sanitize-audit-logs', ['--apply' => true])
            ->expectsOutput('Registros sanitizados: 1')
            ->assertExitCode(0);

        $log->refresh();
        $this->assertSame('Operacao solicitada ao Google Agenda concluida.', $log->message);
        $this->assertEquals([
            'conexao_id' => 10,
            'calendar_id' => 'primary',
            'event_id' => 'event-1',
        ], $log->context_json);
        $this->assertNull($log->metadata_json);
        $this->assertNull($log->raw_excerpt);
        $this->assertSame(365, $log->retention_days);
    }

    public function test_calendar_visibility_rejects_invalid_values(): void
    {
        $this->actingUser(['google_calendar.configurar']);

        $response = $this->patchJson('/api/v1/integrations/google-calendar/calendars/agenda/visibility', [
            'visibility' => 'google',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['visibility']);
    }

    public function test_calendar_visibility_requires_configuration_permission(): void
    {
        $this->actingUser([]);

        $response = $this->patchJson('/api/v1/integrations/google-calendar/calendars/agenda/visibility', [
            'visibility' => 'public',
        ]);

        $response->assertForbidden();
    }

    public function test_destroy_event_requires_cancel_permission(): void
    {
        $this->actingUser([]);

        $response = $this->deleteJson('/api/v1/integrations/google-calendar/events/event-1', [
            'calendar_id' => 'agenda-1',
        ]);

        $response->assertForbidden();
    }

    public function test_destroy_event_requires_calendar_id(): void
    {
        $this->actingUser(['google_calendar.cancelar']);

        $response = $this->deleteJson('/api/v1/integrations/google-calendar/events/event-1');

        $response->assertStatus(422)->assertJsonValidationErrors(['calendar_id']);
    }

    public function test_calendar_sync_defaults_new_calendars_to_private_and_preserves_public_calendars(): void
    {
        $usuario = $this->createUser();
        $conexao = GoogleCalendarConexao::create(['usuario_id' => $usuario->id, 'status' => 'ativa']);
        GoogleCalendarToken::create([
            'conexao_id' => $conexao->id,
            'access_token' => 'access-token',
            'expires_at' => CarbonImmutable::now()->addHour(),
        ]);
        GoogleCalendarCalendar::create([
            'conexao_id' => $conexao->id,
            'calendar_id' => 'agenda-publica',
            'summary' => 'Agenda Pública',
            'enabled' => true,
            'visibility' => 'public',
        ]);

        $client = Mockery::mock(GoogleCalendarClient::class);
        $client->shouldReceive('get')->once()->andReturn([
            'status' => 200,
            'body' => null,
            'json' => ['items' => [
                ['id' => 'agenda-publica', 'summary' => 'Agenda Pública', 'accessRole' => 'owner'],
                ['id' => 'agenda-nova', 'summary' => 'Agenda Nova', 'accessRole' => 'reader'],
            ]],
        ]);

        $service = new GoogleCalendarConnectionService(
            config('google_calendar'),
            Mockery::mock(GoogleCalendarOAuthService::class),
            $client
        );
        $service->syncCalendars($conexao);

        $this->assertDatabaseHas('google_calendar_calendars', [
            'conexao_id' => $conexao->id,
            'calendar_id' => 'agenda-publica',
            'visibility' => 'public',
        ]);
        $this->assertDatabaseHas('google_calendar_calendars', [
            'conexao_id' => $conexao->id,
            'calendar_id' => 'agenda-nova',
            'visibility' => 'private',
        ]);
    }

    public function test_calendar_sync_keeps_new_primary_calendar_disabled_until_explicit_selection(): void
    {
        $usuario = $this->createUser();
        $conexao = GoogleCalendarConexao::create(['usuario_id' => $usuario->id, 'status' => 'ativa']);
        GoogleCalendarToken::create([
            'conexao_id' => $conexao->id,
            'access_token' => 'access-token',
            'expires_at' => CarbonImmutable::now()->addHour(),
        ]);

        $client = Mockery::mock(GoogleCalendarClient::class);
        $client->shouldReceive('get')->once()->andReturn([
            'status' => 200,
            'body' => null,
            'json' => ['items' => [[
                'id' => 'primary',
                'summary' => 'Principal',
                'primary' => true,
                'accessRole' => 'owner',
            ]]],
        ]);

        $service = new GoogleCalendarConnectionService(
            config('google_calendar'),
            Mockery::mock(GoogleCalendarOAuthService::class),
            $client
        );
        $service->syncCalendars($conexao);

        $this->assertDatabaseHas('google_calendar_calendars', [
            'conexao_id' => $conexao->id,
            'calendar_id' => 'primary',
            'primary' => true,
            'enabled' => false,
        ]);
    }

    public function test_update_event_requires_start_and_end_together(): void
    {
        $this->actingUser(['google_calendar.editar']);

        $response = $this->patchJson('/api/v1/integrations/google-calendar/events/event-1', [
            'calendar_id' => 'primary',
            'start' => '2026-05-13T10:00:00-03:00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end']);
    }

    public function test_events_endpoint_rejects_user_without_view_permission(): void
    {
        $this->actingUser([]);

        $response = $this->getJson('/api/v1/integrations/google-calendar/events?start=2026-08-02T09:00:00-03:00&end=2026-08-09T09:00:00-03:00');

        $response->assertForbidden();
        $response->assertJson(['message' => 'Sem permissao para acessar a integracao Google Agenda.']);
    }

    public function test_inactive_user_cannot_use_existing_connection(): void
    {
        $usuario = $this->actingUser(['google_calendar.visualizar', 'google_calendar.configurar']);
        GoogleCalendarConexao::create(['usuario_id' => $usuario->id, 'status' => 'ativa']);
        $usuario->update(['ativo' => false]);

        $this->getJson('/api/v1/integrations/google-calendar/status')
            ->assertForbidden()
            ->assertJson(['message' => 'Usuario inativo.']);
        $this->getJson('/api/v1/integrations/google-calendar/oauth/authorize')
            ->assertForbidden()
            ->assertJson(['message' => 'Usuario inativo.']);

        $this->assertDatabaseHas('google_calendar_conexoes', [
            'usuario_id' => $usuario->id,
            'status' => 'ativa',
        ]);
    }

    public function test_logs_endpoint_returns_new_and_legacy_google_calendar_logs(): void
    {
        $usuario = $this->actingUser(['google_calendar.auditar']);

        $legacy = AuditoriaLog::query()->create([
            'occurred_at' => now()->subMinute(),
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'nivel' => 'info',
            'modulo' => 'google_calendar',
            'acao' => 'create',
            'status' => 'sucesso',
            'message' => 'legacy',
            'actor_id' => $usuario->id,
            'entity_type' => 'google_calendar_event',
            'entity_id' => 'legacy-event',
            'source_system' => 'estoque',
            'source_kind' => 'legacy_table',
            'source_table' => 'google_calendar_logs',
            'source_id' => '1',
            'retention_days' => 365,
        ]);

        $novo = AuditoriaLog::query()->create([
            'occurred_at' => now(),
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'nivel' => 'info',
            'modulo' => 'google_calendar',
            'acao' => 'update',
            'status' => 'sucesso',
            'message' => 'novo',
            'actor_id' => $usuario->id,
            'entity_type' => 'google_calendar_event',
            'entity_id' => 'new-event',
            'source_system' => 'estoque',
            'source_kind' => 'sync',
            'retention_days' => 365,
        ]);

        AuditoriaLog::query()->create([
            'occurred_at' => now(),
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'nivel' => 'info',
            'modulo' => 'conta_azul',
            'acao' => 'update',
            'status' => 'sucesso',
            'message' => 'fora',
            'source_system' => 'estoque',
            'source_kind' => 'sync',
            'retention_days' => 365,
        ]);

        $outroUsuario = $this->createUser();
        AuditoriaLog::query()->create([
            'occurred_at' => now(),
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'nivel' => 'info',
            'modulo' => 'google_calendar',
            'acao' => 'delete',
            'status' => 'sucesso',
            'message' => 'outro usuario',
            'actor_id' => $outroUsuario->id,
            'entity_type' => 'google_calendar_event',
            'entity_id' => 'other-user-event',
            'source_system' => 'estoque',
            'source_kind' => 'sync',
            'retention_days' => 365,
        ]);

        $response = $this->getJson('/api/v1/integrations/google-calendar/logs?per_page=20');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('event_id')->all();
        $this->assertEqualsCanonicalizing([$legacy->entity_id, $novo->entity_id], $ids);
        $this->assertNotContains('other-user-event', $ids);
    }

    /**
     * @param array<int, string> $permissoes
     */
    private function actingUser(array $permissoes): Usuario
    {
        $usuario = $this->createUser();

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, $permissoes, now()->addHour());
        Cache::put('perfis_usuario_' . $usuario->id, [], now()->addHour());

        return $usuario;
    }

    private function createUser(): Usuario
    {
        return Usuario::create([
            'nome' => 'Usuario Google Calendar',
            'email' => 'google-calendar.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);
    }

    private function grantDatabasePermission(Usuario $usuario, string $slug): void
    {
        if (!Schema::hasTable('acesso_usuario_perfil')) {
            Cache::put('permissoes_usuario_' . $usuario->id, [$slug], now()->addHour());
            return;
        }

        $permissaoId = DB::table('acesso_permissoes')
            ->where('slug', $slug)
            ->value('id');

        if ($permissaoId === null) {
            $permissaoValues = [];
            if (Schema::hasColumn('acesso_permissoes', 'nome')) {
                $permissaoValues['nome'] = 'Permissao Google Calendar de teste';
            }
            if (Schema::hasColumn('acesso_permissoes', 'descricao')) {
                $permissaoValues['descricao'] = 'Permissao criada apenas dentro da transacao do teste.';
            }
            if (Schema::hasColumn('acesso_permissoes', 'updated_at')) {
                $permissaoValues['updated_at'] = now();
            }

            DB::table('acesso_permissoes')->updateOrInsert(['slug' => $slug], $permissaoValues);
            $permissaoId = DB::table('acesso_permissoes')->where('slug', $slug)->value('id');
        }

        $perfilId = DB::table('acesso_perfil_permissao')
            ->where('id_permissao', $permissaoId)
            ->value('id_perfil');

        if ($perfilId === null) {
            $perfilId = DB::table('acesso_perfis')->value('id');

            if ($perfilId === null) {
                $perfilValues = [];
                if (Schema::hasColumn('acesso_perfis', 'descricao')) {
                    $perfilValues['descricao'] = 'Perfil criado apenas dentro da transacao do teste.';
                }
                if (Schema::hasColumn('acesso_perfis', 'updated_at')) {
                    $perfilValues['updated_at'] = now();
                }

                DB::table('acesso_perfis')->updateOrInsert(
                    ['nome' => 'Perfil Google Calendar de teste'],
                    $perfilValues
                );
                $perfilId = DB::table('acesso_perfis')
                    ->where('nome', 'Perfil Google Calendar de teste')
                    ->value('id');
            }

            DB::table('acesso_perfil_permissao')->insert([
                'id_perfil' => $perfilId,
                'id_permissao' => $permissaoId,
                ...(Schema::hasColumn('acesso_perfil_permissao', 'updated_at')
                    ? ['updated_at' => now()]
                    : []),
            ]);
        }

        $usuarioPerfilValues = Schema::hasColumn('acesso_usuario_perfil', 'updated_at')
            ? ['updated_at' => now()]
            : [];
        DB::table('acesso_usuario_perfil')->updateOrInsert(
            [
                'id_usuario' => $usuario->id,
                'id_perfil' => $perfilId,
            ],
            $usuarioPerfilValues
        );
    }

    private function clearGoogleCalendarConnections(): void
    {
        GoogleCalendarConexao::query()->delete();
    }
}
