<?php

namespace Tests\Feature;

use App\Models\Aviso;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvisoAtivoEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-03-06 09:00:00');

        DB::table('avisos')->delete();
        DB::table('acesso_usuarios')->where('email', 'like', 'avisos-%@example.com')->delete();

        $usuario = Usuario::create([
            'nome' => 'Usuario Avisos',
            'email' => 'avisos-' . uniqid() . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Cache::put('permissoes_usuario_' . $usuario->id, ['avisos.visualizar'], now()->addHour());

        Sanctum::actingAs($usuario);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_endpoint_avisos_ativos_filtra_por_periodo_e_status(): void
    {
        Aviso::create([
            'titulo' => 'Aviso Vigente',
            'conteudo' => 'Conteúdo válido',
            'ativo' => true,
            'data_inicio' => '2026-03-05 08:00:00',
            'data_fim' => '2026-03-10 18:00:00',
        ]);

        Aviso::create([
            'titulo' => 'Aviso Futuro',
            'conteudo' => 'Conteúdo futuro',
            'ativo' => true,
            'data_inicio' => '2026-03-10 08:00:00',
        ]);

        Aviso::create([
            'titulo' => 'Aviso Expirado',
            'conteudo' => 'Conteúdo expirado',
            'ativo' => true,
            'data_fim' => '2026-03-05 23:59:59',
        ]);

        Aviso::create([
            'titulo' => 'Aviso Inativo',
            'conteudo' => 'Conteúdo inativo',
            'ativo' => false,
        ]);

        $response = $this->getJson('/api/v1/avisos/ativos');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'titulo' => 'Aviso Vigente',
                'ativo' => true,
                'esta_vigente' => true,
            ]);
    }
}
