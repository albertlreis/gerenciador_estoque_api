<?php

namespace Tests\Feature;

use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
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
}
