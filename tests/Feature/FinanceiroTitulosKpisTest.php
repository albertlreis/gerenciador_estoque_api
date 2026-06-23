<?php

namespace Tests\Feature;

use App\Models\CategoriaFinanceira;
use App\Models\CentroCusto;
use App\Models\Cliente;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\Fornecedor;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceiroTitulosKpisTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $usuario;
    private ContaFinanceira $contaFinanceira;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = Usuario::create([
            'nome' => 'Usuario Titulos KPI',
            'email' => 'titulos-kpi-' . uniqid('', true) . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($this->usuario);

        $this->contaFinanceira = ContaFinanceira::create([
            'nome' => 'Conta Titulos KPI',
            'tipo' => 'banco',
            'moeda' => 'BRL',
            'ativo' => true,
            'padrao' => false,
            'saldo_inicial' => 0,
        ]);
    }

    public function test_kpis_contas_pagar_usam_contrato_padronizado_e_filtros_operacionais(): void
    {
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor KPI', 'status' => 1]);
        $categoria = CategoriaFinanceira::create(['nome' => 'Despesa KPI', 'slug' => 'despesa-kpi', 'tipo' => 'despesa', 'ativo' => true]);
        $centro = CentroCusto::create(['nome' => 'ADM KPI', 'slug' => 'adm-kpi', 'ativo' => true]);

        ContaPagar::create([
            'fornecedor_id' => $fornecedor->id,
            'categoria_id' => $categoria->id,
            'centro_custo_id' => $centro->id,
            'descricao' => 'Pagar vencida KPI',
            'data_vencimento' => now()->subDay()->toDateString(),
            'valor_bruto' => 100,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'ABERTA',
            'forma_pagamento' => 'PIX',
        ]);

        ContaPagar::create([
            'fornecedor_id' => $fornecedor->id,
            'categoria_id' => $categoria->id,
            'centro_custo_id' => $centro->id,
            'descricao' => 'Pagar parcial KPI',
            'data_vencimento' => now()->addDay()->toDateString(),
            'valor_bruto' => 200,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'PARCIAL',
            'forma_pagamento' => 'PIX',
        ]);

        $paga = ContaPagar::create([
            'fornecedor_id' => $fornecedor->id,
            'categoria_id' => $categoria->id,
            'centro_custo_id' => $centro->id,
            'descricao' => 'Pagar quitada KPI',
            'data_vencimento' => now()->toDateString(),
            'valor_bruto' => 50,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'PAGA',
            'forma_pagamento' => 'PIX',
        ]);

        ContaPagarPagamento::create([
            'conta_pagar_id' => $paga->id,
            'data_pagamento' => now()->toDateString(),
            'valor' => 50,
            'forma_pagamento' => 'PIX',
            'usuario_id' => $this->usuario->id,
            'conta_financeira_id' => $this->contaFinanceira->id,
        ]);

        ContaPagar::create([
            'fornecedor_id' => $fornecedor->id,
            'descricao' => 'Pagar fora dos filtros',
            'data_vencimento' => now()->subDay()->toDateString(),
            'valor_bruto' => 999,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'ABERTA',
            'forma_pagamento' => 'BOLETO',
        ]);

        $params = [
            'fornecedor_id' => $fornecedor->id,
            'categoria_id' => $categoria->id,
            'centro_custo_id' => $centro->id,
            'forma_pagamento' => 'PIX',
        ];

        $this->getJson('/api/v1/financeiro/contas-pagar/kpis?' . http_build_query($params))
            ->assertOk()
            ->assertJsonPath('total_liquido', 350)
            ->assertJsonPath('total_aberto', 300)
            ->assertJsonPath('total_vencido', 100)
            ->assertJsonPath('total_pago', 50)
            ->assertJsonPath('qtd_abertas', 2)
            ->assertJsonPath('qtd_vencidas', 1)
            ->assertJsonPath('qtd_pagas', 1)
            ->assertJsonPath('valor_pago_periodo', 50)
            ->assertJsonPath('contas_vencidas', 1)
            ->assertJsonPath('contas_pagas', 1);

        $this->getJson('/api/v1/financeiro/contas-pagar?' . http_build_query([...$params, 'em_aberto' => true]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['descricao' => 'Pagar quitada KPI']);
    }

    public function test_kpis_contas_receber_usam_contrato_padronizado_e_filtros_operacionais(): void
    {
        $cliente = Cliente::create(['nome' => 'Cliente KPI', 'tipo' => 'pf']);
        $categoria = CategoriaFinanceira::create(['nome' => 'Receita KPI', 'slug' => 'receita-kpi', 'tipo' => 'receita', 'ativo' => true]);
        $centro = CentroCusto::create(['nome' => 'Comercial KPI', 'slug' => 'comercial-kpi', 'ativo' => true]);

        ContaReceber::create([
            'cliente_id' => $cliente->id,
            'categoria_id' => $categoria->id,
            'centro_custo_id' => $centro->id,
            'descricao' => 'Receber vencida KPI',
            'data_vencimento' => now()->subDay()->toDateString(),
            'valor_bruto' => 120,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'ABERTA',
            'forma_recebimento' => 'BOLETO',
        ]);

        ContaReceber::create([
            'cliente_id' => $cliente->id,
            'categoria_id' => $categoria->id,
            'centro_custo_id' => $centro->id,
            'descricao' => 'Receber parcial KPI',
            'data_vencimento' => now()->addDay()->toDateString(),
            'valor_bruto' => 80,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'PARCIAL',
            'forma_recebimento' => 'BOLETO',
        ]);

        $recebida = ContaReceber::create([
            'cliente_id' => $cliente->id,
            'categoria_id' => $categoria->id,
            'centro_custo_id' => $centro->id,
            'descricao' => 'Receber quitada KPI',
            'data_vencimento' => now()->toDateString(),
            'valor_bruto' => 70,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'PAGA',
            'forma_recebimento' => 'BOLETO',
        ]);

        ContaReceberPagamento::create([
            'conta_receber_id' => $recebida->id,
            'data_pagamento' => now()->toDateString(),
            'valor' => 70,
            'forma_pagamento' => 'BOLETO',
            'usuario_id' => $this->usuario->id,
            'conta_financeira_id' => $this->contaFinanceira->id,
        ]);

        ContaReceber::create([
            'cliente_id' => $cliente->id,
            'descricao' => 'Receber fora dos filtros',
            'data_vencimento' => now()->subDay()->toDateString(),
            'valor_bruto' => 999,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'ABERTA',
            'forma_recebimento' => 'PIX',
        ]);

        $params = [
            'cliente_id' => $cliente->id,
            'categoria_id' => $categoria->id,
            'centro_custo_id' => $centro->id,
            'forma_recebimento' => 'BOLETO',
        ];

        $this->getJson('/api/v1/financeiro/contas-receber/kpis?' . http_build_query($params))
            ->assertOk()
            ->assertJsonPath('total_liquido', 270)
            ->assertJsonPath('total_aberto', 200)
            ->assertJsonPath('total_vencido', 120)
            ->assertJsonPath('total_recebido', 70)
            ->assertJsonPath('qtd_abertas', 2)
            ->assertJsonPath('qtd_vencidas', 1)
            ->assertJsonPath('qtd_recebidas', 1);

        $this->getJson('/api/v1/financeiro/contas-receber?' . http_build_query([...$params, 'em_aberto' => true]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['descricao' => 'Receber quitada KPI']);
    }
}
