<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AniversariosApiTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function autenticar(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Aniversarios',
            'email' => 'aniversarios.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, [], now()->addHour());
    }

    public function test_considera_virada_de_ano(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 12, 29, 10, 0, 0));
        $this->autenticar();

        DB::table('clientes')->insert([
            'nome' => 'Cliente Virada',
            'tipo' => 'pf',
            'documento' => null,
            'data_nascimento' => '1990-01-02',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/aniversarios?tipo=clientes&dias=7');
        $response->assertOk();

        $this->assertCount(1, $response->json());
        $this->assertSame('2027-01-02', $response->json('0.proximo_aniversario'));
    }

    public function test_29_02_em_ano_nao_bissexto_cai_em_28_02(): void
    {
        Carbon::setTestNow(Carbon::create(2025, 2, 27, 10, 0, 0));
        $this->autenticar();

        DB::table('parceiros')->insert([
            'nome' => 'Parceiro Bissexto',
            'tipo' => 'consultor',
            'documento' => '12312312300',
            'data_nascimento' => '2000-02-29',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        $response = $this->getJson('/api/v1/aniversarios?tipo=parceiros&dias=2');
        $response->assertOk();

        $this->assertCount(1, $response->json());
        $this->assertSame('2025-02-28', $response->json('0.proximo_aniversario'));
    }

    public function test_clientes_pj_nao_sao_incluidos_por_padrao(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 1, 10, 0, 0));
        $this->autenticar();

        DB::table('clientes')->insert([
            [
                'nome' => 'Cliente PF',
                'tipo' => 'pf',
                'documento' => null,
                'data_nascimento' => '1990-03-02',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Cliente PJ',
                'tipo' => 'pj',
                'documento' => '12345678000199',
                'data_nascimento' => '1990-03-02',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->getJson('/api/v1/aniversarios?tipo=clientes&dias=3');
        $response->assertOk();

        $nomes = collect($response->json())->pluck('nome')->all();
        $this->assertContains('Cliente PF', $nomes);
        $this->assertNotContains('Cliente PJ', $nomes);
    }
}
