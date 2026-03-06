<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Parceiro;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AniversarioEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-02-28 10:00:00');

        DB::table('clientes')->delete();
        DB::table('parceiros')->delete();
        DB::table('acesso_usuarios')->where('email', 'like', 'aniversarios-%@example.com')->delete();

        $usuario = Usuario::create([
            'nome' => 'Usuario Aniversarios',
            'email' => 'aniversarios-' . uniqid() . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_endpoint_aplica_regra_de_29_de_fevereiro_em_ano_nao_bissexto(): void
    {
        Cliente::create([
            'nome' => 'Cliente Bissexto',
            'tipo' => 'pf',
            'data_nascimento' => '1988-02-29',
        ]);

        Parceiro::create([
            'nome' => 'Parceiro Hoje',
            'tipo' => 'lojista',
            'documento' => '12345678901',
            'status' => 1,
            'data_nascimento' => '1990-02-28',
        ]);

        Parceiro::create([
            'nome' => 'Parceiro Inativo',
            'tipo' => 'lojista',
            'documento' => '12345678902',
            'status' => 0,
            'data_nascimento' => '1990-02-28',
        ]);

        $response = $this->getJson('/api/v1/aniversarios?escopo=dia');

        $response->assertOk()
            ->assertJsonPath('meta.regra_29_02', 'Em anos não bissextos, aniversários em 29/02 são considerados em 28/02.')
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'nome' => 'Cliente Bissexto',
                'proxima_ocorrencia' => '2026-02-28',
                'dias_para_aniversario' => 0,
            ])
            ->assertJsonFragment([
                'nome' => 'Parceiro Hoje',
                'proxima_ocorrencia' => '2026-02-28',
                'dias_para_aniversario' => 0,
            ]);
    }

    public function test_endpoint_filtra_proximos_sete_dias_e_mes(): void
    {
        Cliente::create([
            'nome' => 'Cliente Semana',
            'tipo' => 'pf',
            'data_nascimento' => '1995-03-01',
        ]);

        Cliente::create([
            'nome' => 'Cliente Mes',
            'tipo' => 'pf',
            'data_nascimento' => '1995-03-15',
        ]);

        Parceiro::create([
            'nome' => 'Parceiro Abril',
            'tipo' => 'outro',
            'documento' => '12345678903',
            'status' => 1,
            'data_nascimento' => '1990-04-10',
        ]);

        $semana = $this->getJson('/api/v1/aniversarios?escopo=semana');
        $semana->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nome', 'Cliente Semana')
            ->assertJsonPath('data.0.dias_para_aniversario', 1);

        $mes = $this->getJson('/api/v1/aniversarios?escopo=mes&mes=3');
        $mes->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['nome' => 'Cliente Semana'])
            ->assertJsonFragment(['nome' => 'Cliente Mes']);
    }
}
