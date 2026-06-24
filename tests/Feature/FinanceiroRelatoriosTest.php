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
use App\Models\LancamentoFinanceiro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceiroRelatoriosTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $usuario;
    private ContaFinanceira $conta;
    private CategoriaFinanceira $receita;
    private CategoriaFinanceira $despesa;
    private CentroCusto $centro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limparDadosFinanceiros();

        $suffix = str_replace('.', '-', uniqid('rel-', true));

        $this->usuario = Usuario::create([
            'nome' => 'Usuario Relatorios Financeiros',
            'email' => 'relatorios-financeiros-' . uniqid('', true) . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($this->usuario);

        $this->conta = ContaFinanceira::create([
            'nome' => 'Conta Relatorios',
            'tipo' => 'banco',
            'moeda' => 'BRL',
            'ativo' => true,
            'padrao' => true,
            'saldo_inicial' => 100,
            'data_saldo_inicial' => '2026-05-01',
        ]);

        $this->receita = CategoriaFinanceira::create([
            'nome' => 'Vendas Relatorio',
            'slug' => 'vendas-relatorio-' . $suffix,
            'tipo' => 'receita',
            'ativo' => true,
        ]);

        $this->despesa = CategoriaFinanceira::create([
            'nome' => 'Custos Relatorio',
            'slug' => 'custos-relatorio-' . $suffix,
            'tipo' => 'despesa',
            'ativo' => true,
        ]);

        $this->centro = CentroCusto::create([
            'nome' => 'Centro Relatorio',
            'slug' => 'centro-relatorio-' . $suffix,
            'ativo' => true,
        ]);
    }

    private function limparDadosFinanceiros(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ([
                'lancamentos_financeiros',
                'contas_pagar_pagamentos',
                'contas_receber_pagamentos',
                'contas_pagar',
                'contas_receber',
                'contas_financeiras',
                'categorias_financeiras',
                'centros_custo',
                'fornecedores',
                'clientes',
            ] as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function test_fluxo_caixa_diario_separa_realizado_e_previsto(): void
    {
        LancamentoFinanceiro::create([
            'descricao' => 'Receita realizada',
            'tipo' => 'receita',
            'status' => 'confirmado',
            'categoria_id' => $this->receita->id,
            'centro_custo_id' => $this->centro->id,
            'conta_id' => $this->conta->id,
            'valor' => 500,
            'data_movimento' => '2026-05-10 10:00:00',
            'competencia' => '2026-05-01',
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Despesa realizada',
            'tipo' => 'despesa',
            'status' => 'confirmado',
            'categoria_id' => $this->despesa->id,
            'centro_custo_id' => $this->centro->id,
            'conta_id' => $this->conta->id,
            'valor' => 120,
            'data_movimento' => '2026-05-10 11:00:00',
            'competencia' => '2026-05-01',
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Cancelada fora do realizado',
            'tipo' => 'receita',
            'status' => 'cancelado',
            'conta_id' => $this->conta->id,
            'valor' => 999,
            'data_movimento' => '2026-05-10 12:00:00',
        ]);

        ContaReceber::create([
            'descricao' => 'Receber previsto',
            'data_vencimento' => '2026-05-10',
            'valor_bruto' => 300,
            'valor_liquido' => 300,
            'valor_recebido' => 0,
            'saldo_aberto' => 300,
            'status' => 'ABERTA',
            'categoria_id' => $this->receita->id,
            'centro_custo_id' => $this->centro->id,
        ]);

        ContaPagar::create([
            'descricao' => 'Pagar previsto',
            'data_vencimento' => '2026-05-10',
            'valor_bruto' => 80,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'ABERTA',
            'categoria_id' => $this->despesa->id,
            'centro_custo_id' => $this->centro->id,
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa-diario?' . http_build_query([
            'data_inicio' => '2026-05-10',
            'data_fim' => '2026-05-10',
            'conta_ids' => [$this->conta->id],
        ]));

        $response->assertOk()
            ->assertJsonPath('data.kpis.entradas_realizadas', 500)
            ->assertJsonPath('data.kpis.saidas_realizadas', 120)
            ->assertJsonPath('data.kpis.entradas_previstas', 300)
            ->assertJsonPath('data.kpis.saidas_previstas', 80)
            ->assertJsonPath('data.linhas.0.saldo_inicial', 100)
            ->assertJsonPath('data.linhas.0.saldo_final', 480);
    }

    public function test_fluxo_caixa_usa_saldo_atual_como_ancora_e_retorna_datas_decrescentes(): void
    {
        $this->conta->update([
            'saldo_inicial' => 0,
            'data_saldo_inicial' => '1900-01-01',
            'saldo_atual' => 1000,
            'saldo_atual_em' => '2026-05-03 12:00:00',
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Receita antes da ancora',
            'tipo' => 'receita',
            'status' => 'confirmado',
            'conta_id' => $this->conta->id,
            'valor' => 100,
            'data_movimento' => '2026-05-01 10:00:00',
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Despesa antes da ancora',
            'tipo' => 'despesa',
            'status' => 'confirmado',
            'conta_id' => $this->conta->id,
            'valor' => 50,
            'data_movimento' => '2026-05-02 10:00:00',
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Receita no dia da ancora',
            'tipo' => 'receita',
            'status' => 'confirmado',
            'conta_id' => $this->conta->id,
            'valor' => 200,
            'data_movimento' => '2026-05-03 10:00:00',
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Despesa depois da ancora',
            'tipo' => 'despesa',
            'status' => 'confirmado',
            'conta_id' => $this->conta->id,
            'valor' => 20,
            'data_movimento' => '2026-05-04 10:00:00',
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa-diario?' . http_build_query([
            'data_inicio' => '2026-05-01',
            'data_fim' => '2026-05-04',
            'conta_ids' => [$this->conta->id],
        ]));

        $response->assertOk()
            ->assertJsonPath('data.kpis.saldo_inicial', 750)
            ->assertJsonPath('data.kpis.saldo_final', 980)
            ->assertJsonPath('data.linhas.0.periodo', '04/05/2026')
            ->assertJsonPath('data.linhas.0.saldo_inicial', 1000)
            ->assertJsonPath('data.linhas.0.saldo_final', 980)
            ->assertJsonPath('data.linhas.3.periodo', '01/05/2026')
            ->assertJsonPath('data.linhas.3.saldo_inicial', 750);

        $mensal = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa-mensal?' . http_build_query([
            'data_inicio' => '2026-05-01',
            'data_fim' => '2026-06-30',
            'conta_ids' => [$this->conta->id],
        ]));

        $mensal->assertOk()
            ->assertJsonPath('data.linhas.0.periodo', '06/2026')
            ->assertJsonPath('data.linhas.1.periodo', '05/2026')
            ->assertJsonPath('data.kpis.saldo_final', 980);
    }

    public function test_dre_usa_competencia_e_ignora_transferencias_e_cancelados(): void
    {
        LancamentoFinanceiro::create([
            'descricao' => 'Receita competencia',
            'tipo' => 'receita',
            'status' => 'confirmado',
            'categoria_id' => $this->receita->id,
            'valor' => 1000,
            'data_movimento' => '2026-04-20',
            'competencia' => '2026-05-01',
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Despesa competencia',
            'tipo' => 'despesa',
            'status' => 'confirmado',
            'categoria_id' => $this->despesa->id,
            'valor' => 250,
            'data_movimento' => '2026-05-12',
            'competencia' => '2026-05-01',
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Transferencia recebida',
            'tipo' => 'transferencia',
            'status' => 'confirmado',
            'valor' => 900,
            'data_movimento' => '2026-05-12',
            'competencia' => '2026-05-01',
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Receita cancelada',
            'tipo' => 'receita',
            'status' => 'cancelado',
            'valor' => 700,
            'data_movimento' => '2026-05-12',
            'competencia' => '2026-05-01',
        ]);

        $this->getJson('/api/v1/financeiro/relatorios/dre-gerencial?' . http_build_query([
            'data_inicio' => '2026-05-01',
            'data_fim' => '2026-05-31',
        ]))
            ->assertOk()
            ->assertJsonPath('data.kpis.receitas', 1000)
            ->assertJsonPath('data.kpis.despesas', 250)
            ->assertJsonPath('data.kpis.resultado', 750);
    }

    public function test_posicao_contas_agrupa_por_cliente_e_fornecedor(): void
    {
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor Posicao', 'status' => 1]);
        $cliente = Cliente::create(['nome' => 'Cliente Posicao', 'tipo' => 'pf']);

        $contaPagar = ContaPagar::create([
            'fornecedor_id' => $fornecedor->id,
            'descricao' => 'Titulo pagar posicao',
            'data_vencimento' => now()->subDay()->toDateString(),
            'valor_bruto' => 200,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'PARCIAL',
        ]);

        ContaPagarPagamento::create([
            'conta_pagar_id' => $contaPagar->id,
            'data_pagamento' => now()->toDateString(),
            'valor' => 50,
            'forma_pagamento' => 'PIX',
            'usuario_id' => $this->usuario->id,
            'conta_financeira_id' => $this->conta->id,
        ]);

        ContaReceber::create([
            'cliente_id' => $cliente->id,
            'descricao' => 'Titulo receber posicao',
            'data_vencimento' => now()->addDay()->toDateString(),
            'valor_bruto' => 300,
            'valor_liquido' => 300,
            'valor_recebido' => 0,
            'saldo_aberto' => 300,
            'status' => 'ABERTA',
        ]);

        $this->getJson('/api/v1/financeiro/relatorios/posicao-contas?' . http_build_query([
            'data_inicio' => now()->subDays(2)->toDateString(),
            'data_fim' => now()->addDays(2)->toDateString(),
            'tipo_pessoa' => 'ambos',
        ]))
            ->assertOk()
            ->assertJsonPath('data.kpis.emitido', 500)
            ->assertJsonPath('data.kpis.pago_recebido', 50)
            ->assertJsonPath('data.kpis.saldo_aberto', 450)
            ->assertJsonPath('data.kpis.saldo_vencido', 150);
    }

    public function test_analises_agrupam_por_forma_categoria_e_pessoa(): void
    {
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor Ranking', 'status' => 1]);
        $cliente = Cliente::create(['nome' => 'Cliente Ranking', 'tipo' => 'pf']);

        $contaPagar = ContaPagar::create([
            'fornecedor_id' => $fornecedor->id,
            'categoria_id' => $this->despesa->id,
            'descricao' => 'Titulo pago ranking',
            'data_vencimento' => '2026-05-10',
            'valor_bruto' => 120,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'PAGA',
        ]);

        ContaPagarPagamento::create([
            'conta_pagar_id' => $contaPagar->id,
            'data_pagamento' => '2026-05-11',
            'valor' => 120,
            'forma_pagamento' => 'PIX',
            'usuario_id' => $this->usuario->id,
            'conta_financeira_id' => $this->conta->id,
        ]);

        $contaReceber = ContaReceber::create([
            'cliente_id' => $cliente->id,
            'categoria_id' => $this->receita->id,
            'descricao' => 'Titulo recebido ranking',
            'data_vencimento' => '2026-05-10',
            'valor_bruto' => 220,
            'valor_liquido' => 220,
            'valor_recebido' => 220,
            'saldo_aberto' => 0,
            'status' => 'PAGA',
        ]);

        ContaReceberPagamento::create([
            'conta_receber_id' => $contaReceber->id,
            'data_pagamento' => '2026-05-12',
            'valor' => 220,
            'forma_pagamento' => 'BOLETO',
            'usuario_id' => $this->usuario->id,
            'conta_financeira_id' => $this->conta->id,
        ]);

        $this->getJson('/api/v1/financeiro/relatorios/analise-pagamentos?' . http_build_query([
            'data_inicio' => '2026-05-01',
            'data_fim' => '2026-05-31',
        ]))
            ->assertOk()
            ->assertJsonPath('data.kpis.total_pago', 120)
            ->assertJsonPath('data.linhas.0.forma', 'PIX')
            ->assertJsonPath('data.grupos.ranking_pessoas.0.pessoa', 'Fornecedor Ranking');

        $this->getJson('/api/v1/financeiro/relatorios/analise-recebimentos?' . http_build_query([
            'data_inicio' => '2026-05-01',
            'data_fim' => '2026-05-31',
        ]))
            ->assertOk()
            ->assertJsonPath('data.kpis.total_recebido', 220)
            ->assertJsonPath('data.linhas.0.forma', 'BOLETO')
            ->assertJsonPath('data.grupos.ranking_pessoas.0.pessoa', 'Cliente Ranking');
    }

    public function test_lancamentos_caixa_e_exports(): void
    {
        LancamentoFinanceiro::create([
            'descricao' => 'Lancamento caixa receita antiga',
            'tipo' => 'receita',
            'status' => 'confirmado',
            'conta_id' => $this->conta->id,
            'valor' => 90,
            'data_movimento' => '2026-05-10 10:00:00',
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Lancamento caixa despesa recente',
            'tipo' => 'despesa',
            'status' => 'confirmado',
            'conta_id' => $this->conta->id,
            'valor' => 20,
            'data_movimento' => '2026-05-11 10:00:00',
        ]);

        $params = [
            'data_inicio' => '2026-05-01',
            'data_fim' => '2026-05-31',
        ];

        $this->getJson('/api/v1/financeiro/relatorios/lancamentos-caixa?' . http_build_query($params))
            ->assertOk()
            ->assertJsonPath('data.kpis.entradas', 90)
            ->assertJsonPath('data.kpis.saidas', 20)
            ->assertJsonPath('data.linhas.0.descricao', 'Lancamento caixa despesa recente')
            ->assertJsonPath('data.linhas.1.descricao', 'Lancamento caixa receita antiga');

        foreach (['fluxo-caixa-mensal', 'dre-gerencial', 'posicao-contas', 'analise-pagamentos', 'analise-recebimentos', 'lancamentos-caixa'] as $tipo) {
            $this->get("/api/v1/financeiro/relatorios/{$tipo}/export/excel?" . http_build_query($params))
                ->assertOk()
                ->assertHeader('content-disposition');

            $this->get("/api/v1/financeiro/relatorios/{$tipo}/export/pdf?" . http_build_query($params))
                ->assertOk()
                ->assertHeader('content-disposition');
        }
    }
}
