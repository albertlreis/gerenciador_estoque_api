<?php

namespace Tests\Feature;

use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use App\Models\ContaFinanceira;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceiroCatalogosIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $usuario = Usuario::create([
            'nome' => 'Usuario Catalogos Index',
            'email' => 'catalogos-index-' . Str::uuid() . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
    }

    public function test_centros_custo_paginam_somente_quando_page_ou_per_page_sao_enviados(): void
    {
        foreach (['Administrativo', 'Comercial', 'Operacional'] as $nome) {
            CentroCusto::create([
                'nome' => $nome,
                'slug' => Str::slug($nome),
                'ativo' => true,
                'padrao' => false,
            ]);
        }

        $this->getJson('/api/v1/financeiro/centros-custo')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('meta.total');

        $this->getJson('/api/v1/financeiro/centros-custo?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_categorias_financeiras_paginam_somente_quando_page_ou_per_page_sao_enviados(): void
    {
        foreach (['Aluguel', 'Energia', 'Vendas'] as $nome) {
            CategoriaFinanceira::create([
                'nome' => $nome,
                'slug' => Str::slug($nome),
                'tipo' => $nome === 'Vendas' ? 'receita' : 'despesa',
                'ativo' => true,
                'padrao' => false,
            ]);
        }

        $this->getJson('/api/v1/financeiro/categorias-financeiras?tree=0')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('meta.total');

        $this->getJson('/api/v1/financeiro/categorias-financeiras?tree=0&per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_contas_financeiras_listagem_inclui_saldo_atual_salvo(): void
    {
        ContaFinanceira::create([
            'nome' => 'Banco Principal',
            'slug' => 'banco-principal',
            'tipo' => 'banco',
            'ativo' => true,
            'padrao' => true,
            'moeda' => 'BRL',
            'saldo_inicial' => 100,
            'data_saldo_inicial' => '2026-01-01',
            'saldo_atual' => 1234.56,
            'saldo_atual_em' => '2026-06-19 10:30:00',
        ]);

        $this->getJson('/api/v1/financeiro/contas-financeiras')
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Banco Principal')
            ->assertJsonPath('data.0.data_saldo_inicial', '2026-01-01')
            ->assertJsonPath('data.0.saldo_atual', '1234.56')
            ->assertJsonPath('data.0.saldo_atual_em', '2026-06-19 10:30:00');
    }

    public function test_conta_financeira_cria_e_atualiza_data_saldo_inicial(): void
    {
        $payload = [
            'nome' => 'Caixa Data Base',
            'tipo' => 'caixa',
            'moeda' => 'BRL',
            'ativo' => true,
            'padrao' => false,
            'saldo_inicial' => 500,
            'data_saldo_inicial' => '2026-06-01',
        ];

        $response = $this->postJson('/api/v1/financeiro/contas-financeiras', $payload)
            ->assertCreated()
            ->assertJsonPath('data.nome', 'Caixa Data Base')
            ->assertJsonPath('data.data_saldo_inicial', '2026-06-01');

        $id = $response->json('data.id');

        $this->putJson("/api/v1/financeiro/contas-financeiras/{$id}", [
            ...$payload,
            'nome' => 'Caixa Data Base Editada',
            'data_saldo_inicial' => '2026-06-15',
        ])
            ->assertOk()
            ->assertJsonPath('data.nome', 'Caixa Data Base Editada')
            ->assertJsonPath('data.data_saldo_inicial', '2026-06-15');

        $this->assertDatabaseHas('contas_financeiras', [
            'id' => $id,
            'data_saldo_inicial' => '2026-06-15',
        ]);
    }

    public function test_conta_financeira_exige_data_saldo_inicial_no_upsert(): void
    {
        $this->postJson('/api/v1/financeiro/contas-financeiras', [
            'nome' => 'Conta Sem Data',
            'tipo' => 'banco',
            'moeda' => 'BRL',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['data_saldo_inicial']);
    }
}
