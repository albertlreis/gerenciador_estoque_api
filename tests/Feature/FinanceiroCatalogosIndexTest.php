<?php

namespace Tests\Feature;

use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use App\Models\ContaFinanceira;
use App\Models\LancamentoFinanceiro;
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
            ->assertJsonPath('data.0.saldo_atual_em', '2026-06-19 10:30:00')
            ->assertJsonPath('data.0.saldo_base_origem', 'saldo_atual');
    }

    public function test_contas_financeiras_listagem_calcula_saldo_livro_quando_nao_ha_saldo_atual(): void
    {
        $conta = ContaFinanceira::create([
            'nome' => 'Caixa Livro',
            'slug' => 'caixa-livro',
            'tipo' => 'caixa',
            'ativo' => true,
            'padrao' => true,
            'moeda' => 'BRL',
            'saldo_inicial' => 100,
            'data_saldo_inicial' => '2026-06-10',
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Receita antes da data base',
            'tipo' => 'receita',
            'status' => 'confirmado',
            'conta_id' => $conta->id,
            'valor' => 999,
            'data_movimento' => '2026-06-09 10:00:00',
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Receita na data base',
            'tipo' => 'receita',
            'status' => 'confirmado',
            'conta_id' => $conta->id,
            'valor' => 40,
            'data_movimento' => '2026-06-10 09:00:00',
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Despesa depois da data base',
            'tipo' => 'despesa',
            'status' => 'confirmado',
            'conta_id' => $conta->id,
            'valor' => 15,
            'data_movimento' => '2026-06-11 09:00:00',
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Despesa cancelada',
            'tipo' => 'despesa',
            'status' => 'cancelado',
            'conta_id' => $conta->id,
            'valor' => 500,
            'data_movimento' => '2026-06-11 10:00:00',
        ]);

        $this->getJson('/api/v1/financeiro/contas-financeiras')
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Caixa Livro')
            ->assertJsonPath('data.0.saldo_atual', '125.00')
            ->assertJsonPath('data.0.saldo_atual_em', null)
            ->assertJsonPath('data.0.saldo_base_origem', 'saldo_livro');
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

    public function test_conta_financeira_limpa_saldo_atual_importado_quando_ancora_manual_muda(): void
    {
        $conta = ContaFinanceira::create([
            'nome' => 'Banco Importado',
            'slug' => 'banco-importado',
            'tipo' => 'banco',
            'moeda' => 'BRL',
            'ativo' => true,
            'padrao' => false,
            'saldo_inicial' => 100,
            'data_saldo_inicial' => '2026-06-01',
            'saldo_atual' => 9999.99,
            'saldo_atual_em' => '2026-06-20 10:30:00',
            'meta_json' => [
                'conta_azul' => ['id' => 'external-1'],
                'conta_azul_saldo' => ['saldo_atual' => '9999,99'],
                'observacao_interna' => 'manter',
            ],
        ]);

        $this->putJson("/api/v1/financeiro/contas-financeiras/{$conta->id}", [
            'nome' => 'Banco Importado',
            'tipo' => 'banco',
            'moeda' => 'BRL',
            'ativo' => true,
            'padrao' => false,
            'saldo_inicial' => 50,
            'data_saldo_inicial' => '2026-06-24',
        ])
            ->assertOk()
            ->assertJsonPath('data.nome', 'Banco Importado')
            ->assertJsonPath('data.data_saldo_inicial', '2026-06-24');

        $conta->refresh();

        $this->assertNull($conta->saldo_atual);
        $this->assertNull($conta->saldo_atual_em);
        $this->assertSame(50.0, (float) $conta->saldo_inicial);
        $this->assertSame('2026-06-24', $conta->data_saldo_inicial->format('Y-m-d'));
        $this->assertArrayNotHasKey('conta_azul_saldo', $conta->meta_json);
        $this->assertSame(['id' => 'external-1'], $conta->meta_json['conta_azul']);
        $this->assertSame('manter', $conta->meta_json['observacao_interna']);
    }

    public function test_conta_financeira_limpa_saldo_importado_antigo_mesmo_sem_mudar_ancora_manual(): void
    {
        $conta = ContaFinanceira::create([
            'nome' => 'Caixa Loja/ G.P',
            'slug' => 'caixa-loja-gp',
            'tipo' => 'caixa',
            'moeda' => 'BRL',
            'ativo' => true,
            'padrao' => false,
            'saldo_inicial' => 50,
            'data_saldo_inicial' => '2026-06-24',
            'saldo_atual' => 37089.40,
            'saldo_atual_em' => '2026-06-19 03:24:49',
            'meta_json' => [
                'conta_azul' => ['id' => 'f12847f1-a2f3-449c-b552-969482868d4e'],
                'conta_azul_saldo' => ['saldo_atual' => 37089.40],
            ],
        ]);

        $this->putJson("/api/v1/financeiro/contas-financeiras/{$conta->id}", [
            'nome' => 'Caixa Loja/ G.P',
            'tipo' => 'caixa',
            'moeda' => 'BRL',
            'ativo' => true,
            'padrao' => false,
            'saldo_inicial' => 50,
            'data_saldo_inicial' => '2026-06-24',
        ])->assertOk();

        $conta->refresh();

        $this->assertNull($conta->saldo_atual);
        $this->assertNull($conta->saldo_atual_em);
        $this->assertArrayNotHasKey('conta_azul_saldo', $conta->meta_json);
        $this->assertSame(['id' => 'f12847f1-a2f3-449c-b552-969482868d4e'], $conta->meta_json['conta_azul']);

        $this->getJson('/api/v1/financeiro/contas-financeiras?q=Caixa%20Loja')
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Caixa Loja/ G.P')
            ->assertJsonPath('data.0.saldo_atual', '50.00')
            ->assertJsonPath('data.0.saldo_base_origem', 'saldo_livro');
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
