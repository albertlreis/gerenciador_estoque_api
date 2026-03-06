<?php

namespace Tests\Feature;

use App\Models\Evento;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventoCrudTest extends TestCase
{
    use RefreshDatabase;

    protected Usuario $usuario;
    protected Usuario $participante;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-03-06 10:00:00');

        DB::table('eventos')->delete();
        DB::table('acesso_usuarios')->where('email', 'like', 'eventos-%@example.com')->delete();

        $this->usuario = Usuario::create([
            'nome' => 'Usuario Eventos',
            'email' => 'eventos-' . uniqid() . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $this->participante = Usuario::create([
            'nome' => 'Participante Evento',
            'email' => 'eventos-participante-' . uniqid() . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Cache::put('permissoes_usuario_' . $this->usuario->id, ['home.visualizar'], now()->addHour());

        Sanctum::actingAs($this->usuario);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_cria_lista_e_filtra_eventos_por_periodo_e_usuario(): void
    {
        $store = $this->postJson('/api/v1/eventos', [
            'tipo' => 'reuniao',
            'titulo' => 'Reunião Comercial',
            'descricao' => 'Alinhamento semanal',
            'local' => 'Sala 1',
            'inicio_em' => '2026-03-07T09:00:00-03:00',
            'fim_em' => '2026-03-07T10:30:00-03:00',
            'participantes' => [
                [
                    'user_id' => $this->participante->id,
                    'obrigatorio' => true,
                ],
            ],
        ]);

        $store->assertCreated()
            ->assertJsonPath('data.titulo', 'Reunião Comercial')
            ->assertJsonPath('data.participantes.0.user_id', $this->participante->id)
            ->assertJsonPath('data.participantes.0.obrigatorio', true);

        $eventoId = (int) $store->json('data.id');

        $list = $this->getJson('/api/v1/eventos?from=2026-03-07&to=2026-03-07&usuario_id=' . $this->participante->id);
        $list->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eventoId);

        $show = $this->getJson("/api/v1/eventos/{$eventoId}");
        $show->assertOk()
            ->assertJsonPath('data.participantes.0.usuario.nome', 'Participante Evento');
    }

    public function test_participantes_podem_ser_adicionados_e_removidos(): void
    {
        $evento = Evento::create([
            'tipo' => 'treinamento',
            'titulo' => 'Treinamento Técnico',
            'inicio_em' => '2026-03-08 09:00:00',
            'fim_em' => '2026-03-08 11:00:00',
            'criado_por' => $this->usuario->id,
        ]);

        $adicionar = $this->postJson("/api/v1/eventos/{$evento->id}/participantes", [
            'user_id' => $this->participante->id,
            'obrigatorio' => false,
        ]);

        $adicionar->assertOk()
            ->assertJsonPath('data.participantes.0.user_id', $this->participante->id);

        $remover = $this->deleteJson("/api/v1/eventos/{$evento->id}/participantes/{$this->participante->id}");
        $remover->assertOk()
            ->assertJsonCount(0, 'data.participantes');
    }
}
