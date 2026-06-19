<?php

namespace Tests\Feature;

use App\DTOs\FiltroLancamentoFinanceiroDTO;
use App\Exports\ContasPagarExport;
use App\Exports\ContasReceberExport;
use App\Models\Cliente;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaReceber;
use App\Models\Fornecedor;
use App\Models\LancamentoFinanceiro;
use App\Models\Pedido;
use App\Models\Usuario;
use App\Services\LancamentoFinanceiroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceiroExportsTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = Usuario::create([
            'nome' => 'Usuario Export Financeiro',
            'email' => 'export-financeiro-' . uniqid('', true) . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($this->usuario);
    }

    public function test_contas_pagar_export_respeita_periodo_status_e_vencidas(): void
    {
        ContaPagar::create([
            'descricao' => 'Conta vencida exportavel',
            'data_vencimento' => now()->subDays(5)->toDateString(),
            'valor_bruto' => 100,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'ABERTA',
            'forma_pagamento' => 'PIX',
        ]);

        ContaPagar::create([
            'descricao' => 'Conta paga fora',
            'data_vencimento' => now()->subDays(3)->toDateString(),
            'valor_bruto' => 150,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'PAGA',
            'forma_pagamento' => 'BOLETO',
        ]);

        $params = [
            'data_ini' => now()->subDays(10)->toDateString(),
            'data_fim' => now()->toDateString(),
            'vencidas' => true,
            'forma_pagamento' => 'PIX',
        ];

        $this->assertSame(1, (new ContasPagarExport($params))->collection()->count());

        $this->get('/api/v1/financeiro/contas-pagar/export/excel?' . http_build_query($params))
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->get('/api/v1/financeiro/contas-pagar/export/pdf?' . http_build_query($params))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_contas_receber_export_respeita_cliente_status_e_periodo(): void
    {
        $clienteDentro = Cliente::create(['nome' => 'Cliente Exportavel', 'tipo' => 'pf']);
        $clienteFora = Cliente::create(['nome' => 'Cliente Fora', 'tipo' => 'pf']);
        $clienteDireto = Cliente::create(['nome' => 'Cliente Direto Exportavel', 'tipo' => 'pf']);

        $pedidoDentro = Pedido::create([
            'id_cliente' => $clienteDentro->id,
            'id_usuario' => $this->usuario->id,
            'numero_externo' => 'PED-EXP-1',
        ]);
        $pedidoFora = Pedido::create([
            'id_cliente' => $clienteFora->id,
            'id_usuario' => $this->usuario->id,
            'numero_externo' => 'PED-FORA-1',
        ]);

        ContaReceber::create([
            'pedido_id' => $pedidoDentro->id,
            'descricao' => 'Receber exportavel',
            'data_vencimento' => '2026-05-10',
            'valor_bruto' => 200,
            'valor_liquido' => 200,
            'valor_recebido' => 0,
            'saldo_aberto' => 200,
            'status' => 'ABERTA',
        ]);
        ContaReceber::create([
            'pedido_id' => $pedidoFora->id,
            'descricao' => 'Receber fora',
            'data_vencimento' => '2026-05-10',
            'valor_bruto' => 300,
            'valor_liquido' => 300,
            'valor_recebido' => 0,
            'saldo_aberto' => 300,
            'status' => 'ABERTA',
        ]);
        ContaReceber::create([
            'cliente_id' => $clienteDireto->id,
            'descricao' => 'Receber cliente direto',
            'data_vencimento' => '2026-05-11',
            'valor_bruto' => 250,
            'valor_liquido' => 250,
            'valor_recebido' => 0,
            'saldo_aberto' => 250,
            'status' => 'ABERTA',
        ]);

        $params = [
            'cliente' => 'Exportavel',
            'status' => 'ABERTA',
            'data_ini' => '2026-05-01',
            'data_fim' => '2026-05-31',
        ];

        $this->assertSame(2, ContasReceberExport::query($params)->count());
        $this->assertSame(1, ContasReceberExport::query(['cliente_id' => $clienteDireto->id])->count());

        $this->getJson('/api/v1/financeiro/contas-receber?' . http_build_query(['busca' => 'Receber exportavel']))
            ->assertOk()
            ->assertJsonPath('data.0.pedido.numero', 'PED-EXP-1')
            ->assertJsonPath('data.0.pedido_numero', 'PED-EXP-1')
            ->assertJsonPath('data.0.cliente_id', $clienteDentro->id)
            ->assertJsonPath('data.0.cliente_nome', 'Cliente Exportavel');

        $this->getJson('/api/v1/financeiro/contas-receber?' . http_build_query(['busca' => 'Receber cliente direto']))
            ->assertOk()
            ->assertJsonPath('data.0.cliente_id', $clienteDireto->id)
            ->assertJsonPath('data.0.cliente_nome', 'Cliente Direto Exportavel');

        $this->get('/api/v1/financeiro/contas-receber/export/excel?' . http_build_query($params))
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->get('/api/v1/financeiro/contas-receber/export/pdf?' . http_build_query($params))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_contas_pagar_index_exibe_fornecedor_soft_deleted(): void
    {
        $fornecedor = Fornecedor::create([
            'nome' => 'Fornecedor Historico',
            'cnpj' => '12345678000199',
            'status' => 1,
        ]);

        ContaPagar::create([
            'fornecedor_id' => $fornecedor->id,
            'descricao' => 'Conta fornecedor historico',
            'data_vencimento' => now()->addDay()->toDateString(),
            'valor_bruto' => 120,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'ABERTA',
            'forma_pagamento' => 'PIX',
        ]);

        $fornecedor->delete();

        $this->getJson('/api/v1/financeiro/contas-pagar?' . http_build_query(['busca' => 'historico']))
            ->assertOk()
            ->assertJsonPath('data.0.fornecedor.nome', 'Fornecedor Historico')
            ->assertJsonPath('data.0.fornecedor_nome', 'Fornecedor Historico');
    }

    public function test_lancamentos_exports_respeitam_filtros_e_retornam_arquivo(): void
    {
        $conta = ContaFinanceira::create([
            'nome' => 'Conta Exportacao',
            'tipo' => 'banco',
            'moeda' => 'BRL',
            'ativo' => true,
            'padrao' => false,
            'saldo_inicial' => 0,
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Receita exportavel',
            'tipo' => 'receita',
            'status' => 'confirmado',
            'conta_id' => $conta->id,
            'valor' => 100,
            'data_movimento' => '2026-05-10 10:00:00',
        ]);
        LancamentoFinanceiro::create([
            'descricao' => 'Despesa fora',
            'tipo' => 'despesa',
            'status' => 'confirmado',
            'conta_id' => $conta->id,
            'valor' => 50,
            'data_movimento' => '2026-05-10 11:00:00',
        ]);

        $params = [
            'conta_id' => $conta->id,
            'tipo' => 'receita',
            'status' => 'confirmado',
            'data_inicio' => '2026-05-01',
            'data_fim' => '2026-05-31',
        ];

        $dto = new FiltroLancamentoFinanceiroDTO($params);
        $this->assertSame(1, app(LancamentoFinanceiroService::class)->listarParaExportacao($dto)->count());

        $this->get('/api/v1/financeiro/lancamentos/export/excel?' . http_build_query($params))
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->get('/api/v1/financeiro/lancamentos/export/pdf?' . http_build_query($params))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_extrato_pdf_e_excel_retornam_arquivo(): void
    {
        $conta = ContaFinanceira::create([
            'nome' => 'Conta Extrato',
            'tipo' => 'banco',
            'moeda' => 'BRL',
            'ativo' => true,
            'padrao' => false,
            'saldo_inicial' => 10,
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Receita extrato',
            'tipo' => 'receita',
            'status' => 'confirmado',
            'conta_id' => $conta->id,
            'valor' => 100,
            'data_movimento' => '2026-05-10 10:00:00',
        ]);

        $params = [
            'conta_id' => $conta->id,
            'data_inicio' => '2026-05-01',
            'data_fim' => '2026-05-31',
        ];

        $this->get('/api/v1/financeiro/extrato/export/pdf?' . http_build_query($params))
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->get('/api/v1/financeiro/extrato/export/excel?' . http_build_query($params))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_devedores_exports_usam_rota_de_relatorios(): void
    {
        $cliente = Cliente::create(['nome' => 'Cliente Devedor', 'tipo' => 'pf']);
        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $this->usuario->id,
            'numero_externo' => 'PED-DEV-1',
        ]);

        ContaReceber::create([
            'pedido_id' => $pedido->id,
            'descricao' => 'Titulo devedor',
            'data_vencimento' => '2026-05-10',
            'valor_bruto' => 100,
            'valor_liquido' => 100,
            'valor_recebido' => 0,
            'saldo_aberto' => 100,
            'status' => 'ABERTA',
        ]);

        $this->get('/api/v1/relatorios/devedores/export/excel')
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->get('/api/v1/relatorios/devedores/export/pdf')
            ->assertOk()
            ->assertHeader('content-disposition');
    }
}
