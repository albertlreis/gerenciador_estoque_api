<?php

namespace Tests\Feature\Financeiro;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SlugAutomaticoTest extends TestCase
{
    use RefreshDatabase;

    private function autenticar(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Financeiro',
            'email' => 'slug.financeiro.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
    }

    public function test_categoria_financeira_gera_slug_automatico_e_unico(): void
    {
        $this->autenticar();

        $primeira = $this->postJson('/api/v1/financeiro/categorias-financeiras', [
            'nome' => 'Receita Recorrente',
            'tipo' => 'receita',
        ])->assertCreated();

        $segunda = $this->postJson('/api/v1/financeiro/categorias-financeiras', [
            'nome' => 'Receita Recorrente',
            'tipo' => 'receita',
            'slug' => 'nao-deve-ser-usado',
        ])->assertCreated();

        $primeiroId = $primeira->json('data.id');
        $segundoId = $segunda->json('data.id');

        $this->assertDatabaseHas('categorias_financeiras', [
            'id' => $primeiroId,
            'slug' => 'receita-recorrente',
        ]);

        $this->assertDatabaseHas('categorias_financeiras', [
            'id' => $segundoId,
            'slug' => 'receita-recorrente-2',
        ]);
    }

    public function test_centro_custo_atualiza_slug_quando_nome_muda(): void
    {
        $this->autenticar();

        $store = $this->postJson('/api/v1/financeiro/centros-custo', [
            'nome' => 'Operacoes Loja',
        ])->assertCreated();

        $centroId = $store->json('data.id');

        $this->putJson("/api/v1/financeiro/centros-custo/{$centroId}", [
            'nome' => 'Operacoes Campo',
        ])->assertOk();

        $this->assertDatabaseHas('centros_custo', [
            'id' => $centroId,
            'slug' => 'operacoes-campo',
        ]);
    }
}
