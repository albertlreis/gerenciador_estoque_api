<?php

namespace Tests\Feature;

use App\Models\LancamentoFinanceiro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceiroLancamentoReciboTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $usuario = Usuario::create([
            'nome' => 'Usuario Recibo Financeiro',
            'email' => 'recibo-financeiro-' . uniqid('', true) . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
    }

    public function test_gera_recibo_para_receita_confirmada_com_pessoa(): void
    {
        $lancamento = $this->criarLancamento([
            'descricao' => 'Venda de poltrona',
            'tipo' => 'receita',
            'recibo_pessoa_nome' => 'Cliente Recibo',
            'recibo_pessoa_documento' => '123.456.789-00',
        ]);

        $response = $this->get("/api/v1/financeiro/lancamentos/{$lancamento->id}/recibo.pdf")
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->assertStringContainsString(
            "recibo-movimento-{$lancamento->id}.pdf",
            $response->headers->get('content-disposition')
        );
    }

    public function test_gera_recibo_para_despesa_confirmada_com_pessoa(): void
    {
        $lancamento = $this->criarLancamento([
            'descricao' => 'Frete de entrega',
            'tipo' => 'despesa',
            'recibo_pessoa_nome' => 'Fornecedor Recibo',
        ]);

        $response = $this->get("/api/v1/financeiro/lancamentos/{$lancamento->id}/recibo.pdf")
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->assertStringContainsString(
            "recibo-movimento-{$lancamento->id}.pdf",
            $response->headers->get('content-disposition')
        );
    }

    public function test_bloqueia_recibo_para_lancamentos_nao_elegiveis(): void
    {
        $casos = [
            ['status' => 'cancelado', 'tipo' => 'receita', 'recibo_pessoa_nome' => 'Cliente'],
            ['status' => 'confirmado', 'tipo' => 'transferencia', 'recibo_pessoa_nome' => 'Cliente'],
            ['status' => 'confirmado', 'tipo' => 'ajuste', 'recibo_pessoa_nome' => 'Cliente'],
            ['status' => 'confirmado', 'tipo' => 'receita', 'recibo_pessoa_nome' => null],
        ];

        foreach ($casos as $caso) {
            $lancamento = $this->criarLancamento($caso);

            $this->getJson("/api/v1/financeiro/lancamentos/{$lancamento->id}/recibo.pdf")
                ->assertStatus(422);
        }
    }

    private function criarLancamento(array $overrides = []): LancamentoFinanceiro
    {
        return LancamentoFinanceiro::create(array_merge([
            'descricao' => 'Movimento com recibo',
            'tipo' => 'receita',
            'status' => 'confirmado',
            'valor' => 369,
            'data_movimento' => '2026-06-19 10:00:00',
            'recibo_pessoa_nome' => 'Pessoa Recibo',
        ], $overrides));
    }
}
