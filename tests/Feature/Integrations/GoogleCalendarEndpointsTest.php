<?php

namespace Tests\Feature\Integrations;

use App\Models\AuditoriaLog;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoogleCalendarEndpointsTest extends TestCase
{
    use DatabaseTransactions;

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

        $response = $this->getJson('/api/v1/integrations/google-calendar/logs?per_page=20');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('event_id')->all();
        $this->assertEqualsCanonicalizing([$legacy->entity_id, $novo->entity_id], $ids);
    }

    /**
     * @param array<int, string> $permissoes
     */
    private function actingUser(array $permissoes): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Google Calendar',
            'email' => 'google-calendar.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, $permissoes, now()->addHour());
        Cache::put('perfis_usuario_' . $usuario->id, [], now()->addHour());

        return $usuario;
    }
}
