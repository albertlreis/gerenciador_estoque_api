<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Parceiro;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AniversariosEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_considera_virada_de_ano_no_intervalo(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 12, 28, 10, 0, 0));
        $this->autenticar();

        Cliente::create([
            'nome' => 'Cliente Ano Novo',
            'tipo' => 'pf',
            'documento' => null,
            'data_nascimento' => '2000-01-01',
        ]);

        $response = $this->getJson('/api/v1/aniversarios?tipo=clientes&dias=7');
        $response->assertOk();
        $response->assertJsonFragment([
            'nome' => 'Cliente Ano Novo',
            'proximo_aniversario' => '2027-01-01',
        ]);
    }

    public function test_trata_29_02_como_28_02_em_ano_nao_bissexto(): void
    {
        Carbon::setTestNow(Carbon::create(2025, 2, 27, 9, 0, 0));
        $this->autenticar();

        Parceiro::create([
            'nome' => 'Parceiro Bissexto',
            'tipo' => 'consultor',
            'documento' => '123',
            'data_nascimento' => '2000-02-29',
            'status' => 1,
        ]);

        $response = $this->getJson('/api/v1/aniversarios?tipo=parceiros&dias=2');
        $response->assertOk();
        $response->assertJsonFragment([
            'nome' => 'Parceiro Bissexto',
            'dia_mes' => '28/02',
            'proximo_aniversario' => '2025-02-28',
        ]);
    }

    public function test_clientes_pj_nao_aparecem_por_padrao(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 10, 11, 0, 0));
        $this->autenticar();

        Cliente::create([
            'nome' => 'Cliente PJ',
            'tipo' => 'pj',
            'documento' => null,
            'data_nascimento' => '2000-03-11',
        ]);

        Cliente::create([
            'nome' => 'Cliente PF',
            'tipo' => 'pf',
            'documento' => null,
            'data_nascimento' => '2000-03-11',
        ]);

        $response = $this->getJson('/api/v1/aniversarios?tipo=clientes&dias=2');
        $response->assertOk();
        $nomes = collect($response->json())->pluck('nome')->all();

        $this->assertContains('Cliente PF', $nomes);
        $this->assertNotContains('Cliente PJ', $nomes);
    }

    private function autenticar(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Aniversarios',
            'email' => 'aniversarios.' . uniqid() . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
    }
}

