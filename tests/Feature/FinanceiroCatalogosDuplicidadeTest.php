<?php

namespace Tests\Feature;

use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceiroCatalogosDuplicidadeTest extends TestCase
{
    use RefreshDatabase;

    private function autenticar(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Catalogos Financeiros',
            'email' => 'catalogos-financeiros@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
    }

    public function test_bloqueia_categoria_financeira_duplicada_por_nome_normalizado_no_mesmo_tipo(): void
    {
        $this->autenticar();

        $this->postJson('/api/v1/financeiro/categorias-financeiras', [
            'nome' => 'Agua   e Luz',
            'tipo' => 'despesa',
            'ativo' => true,
        ])->assertCreated();

        $this->postJson('/api/v1/financeiro/categorias-financeiras', [
            'nome' => 'ÁGUA e   luz',
            'tipo' => 'despesa',
            'ativo' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nome']);
    }

    public function test_permite_categoria_financeira_com_mesmo_nome_normalizado_em_tipos_diferentes(): void
    {
        $this->autenticar();

        $this->postJson('/api/v1/financeiro/categorias-financeiras', [
            'nome' => 'Reembolso Cliente',
            'tipo' => 'despesa',
            'ativo' => true,
        ])->assertCreated();

        $this->postJson('/api/v1/financeiro/categorias-financeiras', [
            'nome' => 'reembolso  cliente',
            'tipo' => 'receita',
            'ativo' => true,
        ])->assertCreated();
    }

    public function test_bloqueia_centro_de_custo_duplicado_por_nome_normalizado(): void
    {
        $this->autenticar();

        $this->postJson('/api/v1/financeiro/centros-custo', [
            'nome' => 'Administrativo Geral',
            'ativo' => true,
        ])->assertCreated();

        $this->postJson('/api/v1/financeiro/centros-custo', [
            'nome' => 'administrativo   geral',
            'ativo' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nome']);
    }

    public function test_bloqueia_forma_de_pagamento_duplicada_por_nome_normalizado(): void
    {
        $this->autenticar();

        $this->postJson('/api/v1/financeiro/formas-pagamento', [
            'nome' => 'Pix',
            'ativo' => true,
        ])->assertCreated();

        $this->postJson('/api/v1/financeiro/formas-pagamento', [
            'nome' => 'PÍX',
            'ativo' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nome']);
    }

    public function test_update_do_proprio_registro_nao_gera_falso_positivo(): void
    {
        $this->autenticar();

        $categoria = CategoriaFinanceira::create([
            'nome' => 'Marketing',
            'slug' => 'marketing',
            'tipo' => 'despesa',
            'ativo' => true,
            'padrao' => false,
        ]);

        $centro = CentroCusto::create([
            'nome' => 'Comercial',
            'slug' => 'comercial',
            'ativo' => true,
            'padrao' => false,
        ]);

        $this->putJson("/api/v1/financeiro/categorias-financeiras/{$categoria->id}", [
            'nome' => 'MÁRKETING',
            'tipo' => 'despesa',
            'ativo' => true,
            'padrao' => false,
        ])->assertOk();

        $this->putJson("/api/v1/financeiro/centros-custo/{$centro->id}", [
            'nome' => 'comercial',
            'ativo' => true,
            'padrao' => false,
        ])->assertOk();
    }

    public function test_duplicata_inativa_orienta_reativacao(): void
    {
        $this->autenticar();

        CentroCusto::create([
            'nome' => 'Operacoes',
            'slug' => 'operacoes',
            'ativo' => false,
            'padrao' => false,
        ]);

        $this->postJson('/api/v1/financeiro/centros-custo', [
            'nome' => 'operações',
            'ativo' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.nome.0', 'Ja existe um centro de custo inativo com este nome. Reative o cadastro existente para usa-lo.');
    }
}
