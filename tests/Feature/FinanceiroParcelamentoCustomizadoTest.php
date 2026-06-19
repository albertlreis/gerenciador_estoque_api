<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\FinanceiroParcelamento;
use App\Models\Fornecedor;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceiroParcelamentoCustomizadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $usuario = Usuario::create([
            'nome' => 'Usuario Financeiro Parcelamento',
            'email' => 'financeiro-parcelamento-' . uniqid('', true) . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
    }

    public function test_conta_pagar_parcelada_respeita_vencimentos_customizados(): void
    {
        $fornecedor = Fornecedor::create([
            'nome' => 'Fornecedor Parcelas Custom',
            'status' => 1,
        ]);

        $response = $this->postJson('/api/v1/financeiro/contas-pagar', [
            'fornecedor_id' => $fornecedor->id,
            'descricao' => 'Despesa com agenda customizada',
            'data_emissao' => '2026-06-19',
            'data_vencimento' => '2026-06-25',
            'valor_bruto' => 300,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'forma_pagamento' => 'PIX',
            'parcelamento' => [
                'quantidade_parcelas' => 3,
                'valor_entrada' => 0,
                'intervalo_meses' => 1,
                'primeiro_vencimento' => '2026-06-25',
                'parcelas' => [
                    ['parcela_numero' => 1, 'vencimento' => '2026-06-25', 'valor' => 100, 'is_entrada' => false],
                    ['parcela_numero' => 2, 'vencimento' => '2026-07-29', 'valor' => 100, 'is_entrada' => false],
                    ['parcela_numero' => 3, 'vencimento' => '2026-09-03', 'valor' => 100, 'is_entrada' => false],
                ],
            ],
        ]);

        $response->assertCreated();

        $parcelamento = FinanceiroParcelamento::query()->where('tipo', 'pagar')->firstOrFail();

        $this->assertSame(
            ['2026-06-25', '2026-07-29', '2026-09-03'],
            ContaPagar::query()
                ->where('parcelamento_id', $parcelamento->id)
                ->orderBy('parcela_numero')
                ->pluck('data_vencimento')
                ->map(fn ($date) => $date->format('Y-m-d'))
                ->all()
        );
    }

    public function test_conta_receber_parcelada_com_pagamento_inicial_baixa_entrada(): void
    {
        $contaFinanceira = ContaFinanceira::create([
            'nome' => 'Conta Recebimento Inicial',
            'tipo' => 'banco',
            'moeda' => 'BRL',
            'ativo' => true,
            'padrao' => true,
            'saldo_inicial' => 0,
        ]);

        $response = $this->postJson('/api/v1/financeiro/contas-receber', [
            'descricao' => 'Receita parcelada com entrada paga',
            'data_emissao' => '2026-06-19',
            'data_vencimento' => '2026-07-10',
            'valor_bruto' => 150,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'forma_recebimento' => 'PIX',
            'parcelamento' => [
                'quantidade_parcelas' => 2,
                'valor_entrada' => 30,
                'intervalo_meses' => 1,
                'primeiro_vencimento' => '2026-07-10',
                'data_entrada' => '2026-06-19',
                'parcelas' => [
                    ['parcela_numero' => 0, 'vencimento' => '2026-06-19', 'valor' => 30, 'is_entrada' => true],
                    ['parcela_numero' => 1, 'vencimento' => '2026-07-10', 'valor' => 60, 'is_entrada' => false],
                    ['parcela_numero' => 2, 'vencimento' => '2026-08-15', 'valor' => 60, 'is_entrada' => false],
                ],
            ],
            'pagamento_inicial' => [
                'valor' => 30,
                'data_pagamento' => '2026-06-19',
                'forma_pagamento' => 'PIX',
                'conta_financeira_id' => $contaFinanceira->id,
            ],
        ]);

        $response->assertCreated();

        $parcelamento = FinanceiroParcelamento::query()->where('tipo', 'receber')->firstOrFail();
        $entrada = ContaReceber::query()
            ->where('parcelamento_id', $parcelamento->id)
            ->where('is_entrada', true)
            ->firstOrFail();

        $this->assertSame('2026-06-19', $entrada->data_vencimento->format('Y-m-d'));
        $this->assertSame(1, ContaReceberPagamento::query()->where('conta_receber_id', $entrada->id)->count());
        $this->assertSame('0', (string) $entrada->fresh()->saldo_aberto);
    }

    public function test_rejeita_parcelas_com_soma_diferente_do_valor_liquido(): void
    {
        $response = $this->postJson('/api/v1/financeiro/contas-pagar', [
            'descricao' => 'Despesa com soma invalida',
            'data_emissao' => '2026-06-19',
            'data_vencimento' => '2026-06-25',
            'valor_bruto' => 300,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'forma_pagamento' => 'PIX',
            'parcelamento' => [
                'quantidade_parcelas' => 2,
                'valor_entrada' => 0,
                'intervalo_meses' => 1,
                'primeiro_vencimento' => '2026-06-25',
                'parcelas' => [
                    ['parcela_numero' => 1, 'vencimento' => '2026-06-25', 'valor' => 100, 'is_entrada' => false],
                    ['parcela_numero' => 2, 'vencimento' => '2026-07-25', 'valor' => 100, 'is_entrada' => false],
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parcelamento.parcelas');

        $this->assertSame(0, ContaPagar::query()->where('descricao', 'like', 'Despesa com soma invalida%')->count());
        $this->assertSame(0, ContaPagarPagamento::query()->count());
    }
}
