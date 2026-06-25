<?php

namespace Tests\Feature;

use App\Models\ConciliacaoBancariaTransacao;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\Fornecedor;
use App\Models\LancamentoFinanceiro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConciliacaoBancariaOfxTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetFinanceiroConciliacaoState();

        $this->usuario = Usuario::create([
            'nome' => 'Usuario Conciliacao',
            'email' => 'conciliacao-' . uniqid('', true) . '@example.test',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($this->usuario);
    }

    protected function tearDown(): void
    {
        $this->resetFinanceiroConciliacaoState();

        parent::tearDown();
    }

    private function resetFinanceiroConciliacaoState(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ([
            'conciliacao_bancaria_transacoes',
            'conciliacao_bancaria_importacoes',
            'contas_pagar_pagamentos',
            'lancamentos_financeiros',
            'transferencias_financeiras',
            'contas_pagar',
            'contas_financeiras',
            'fornecedores',
        ] as $table) {
            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_importa_ofx_sugere_match_e_nao_duplica_transacoes(): void
    {
        $conta = $this->contaBb();
        $this->contaPagarKasa();

        $response = $this->postOfx($conta);

        $response
            ->assertCreated()
            ->assertJsonPath('data.banco_codigo', '001')
            ->assertJsonPath('data.resumo.total', 2)
            ->assertJsonPath('data.resumo.sugeridas', 1)
            ->assertJsonPath('data.resumo.pendentes', 1);

        $this->assertSame(2, ConciliacaoBancariaTransacao::query()->count());
        $this->assertDatabaseHas('conciliacao_bancaria_transacoes', [
            'fit_id' => '2026061511710450',
            'status' => 'sugerido',
            'candidato_tipo' => 'conta_pagar',
        ]);

        $this->postOfx($conta)->assertCreated();
        $this->assertSame(2, ConciliacaoBancariaTransacao::query()->count());
    }

    public function test_rejeita_ofx_de_outra_conta_bancaria(): void
    {
        $conta = ContaFinanceira::create([
            'nome' => 'Outra conta',
            'tipo' => 'banco',
            'banco_codigo' => '237',
            'conta' => '999999',
            'conta_dv' => '0',
            'moeda' => 'BRL',
            'ativo' => true,
            'saldo_inicial' => 0,
        ]);

        $this->postOfx($conta)->assertUnprocessable();
    }

    public function test_confirmacao_cria_pagamento_lancamento_e_atualiza_saldo(): void
    {
        $conta = $this->contaBb();
        ContaPagar::query()->where('descricao', 'like', '%KASA%')->update(['status' => 'PAGA']);
        $titulo = $this->contaPagarKasa();
        $this->postOfx($conta)->assertCreated();

        $transacao = ConciliacaoBancariaTransacao::query()
            ->where('candidato_tipo', 'conta_pagar')
            ->where('candidato_id', $titulo->id)
            ->firstOrFail();

        $this->postJson("/api/v1/financeiro/conciliacao-bancaria/transacoes/{$transacao->id}/confirmar")
            ->assertOk()
            ->assertJsonPath('data.status', 'conciliado');

        $this->assertSame(1, ContaPagarPagamento::query()->where('conta_pagar_id', $titulo->id)->count());
        $this->assertSame(1, LancamentoFinanceiro::query()->where('referencia_id', $titulo->id)->where('tipo', 'despesa')->count());
        $this->assertSame('PAGA', (string) $titulo->fresh()->status->value);
        $this->assertSame('76074.52', (string) $conta->fresh()->saldo_atual);
        $this->assertSame('2026-06-16 00:00:00', $conta->fresh()->saldo_atual_em->format('Y-m-d H:i:s'));
    }

    public function test_confirmacao_de_pagamento_existente_nao_duplica_baixa(): void
    {
        $conta = $this->contaBb();
        $titulo = $this->contaPagarKasa();
        $pagamento = ContaPagarPagamento::create([
            'conta_pagar_id' => $titulo->id,
            'data_pagamento' => '2026-06-15',
            'valor' => 17104.50,
            'forma_pagamento' => 'PIX',
            'usuario_id' => $this->usuario->id,
            'conta_financeira_id' => $conta->id,
        ]);

        LancamentoFinanceiro::create([
            'descricao' => 'Pagamento existente',
            'tipo' => 'despesa',
            'status' => 'confirmado',
            'conta_id' => $conta->id,
            'valor' => 17104.50,
            'data_movimento' => '2026-06-15 00:00:00',
            'competencia' => '2026-06-01',
            'referencia_type' => ContaPagar::class,
            'referencia_id' => $titulo->id,
            'pagamento_type' => ContaPagarPagamento::class,
            'pagamento_id' => $pagamento->id,
        ]);

        $this->postOfx($conta)->assertCreated();

        $transacao = ConciliacaoBancariaTransacao::query()
            ->where('candidato_tipo', 'conta_pagar_pagamento')
            ->firstOrFail();

        $this->postJson("/api/v1/financeiro/conciliacao-bancaria/transacoes/{$transacao->id}/confirmar")
            ->assertOk()
            ->assertJsonPath('data.pagamento_id', $pagamento->id);

        $this->assertSame(1, ContaPagarPagamento::query()->where('conta_pagar_id', $titulo->id)->count());
    }

    public function test_reanalise_preenche_identificacao_provavel_sem_sugerir_confirmacao(): void
    {
        $conta = $this->contaBb();
        ContaPagar::query()->where('descricao', 'like', '%KASA%')->update(['status' => 'PAGA']);
        $this->historicoKasaPago();

        $this->postOfx($conta)->assertCreated();

        $transacao = ConciliacaoBancariaTransacao::query()
            ->where('fit_id', '2026061511710450')
            ->where('conta_financeira_id', $conta->id)
            ->firstOrFail();

        $this->assertSame('pendente', $transacao->status);
        $this->assertNull($transacao->candidato_tipo);

        $transacao->forceFill([
            'candidato_score' => null,
            'candidato_motivo' => 'Conta a pagar sem candidato por valor e data',
            'candidato_json' => null,
        ])->save();

        $this->postJson("/api/v1/financeiro/conciliacao-bancaria/importacoes/{$transacao->importacao_id}/reanalisar")
            ->assertOk()
            ->assertJsonPath('data.resumo.sugeridas', 0)
            ->assertJsonPath('data.resumo.pendentes', 2)
            ->assertJsonPath('data.transacoes.1.status', 'pendente')
            ->assertJsonPath('data.transacoes.1.candidato_tipo', null)
            ->assertJsonPath('data.transacoes.1.candidato.0.tipo', 'fornecedor_provavel')
            ->assertJsonPath('data.transacoes.1.candidato.0.confirmavel', false);

        $transacao->refresh();
        $this->assertSame('pendente', $transacao->status);
        $this->assertNull($transacao->candidato_tipo);
        $this->assertSame('fornecedor_provavel', $transacao->candidato_json[0]['tipo']);
    }

    public function test_define_fornecedor_provavel_sem_liberar_confirmacao(): void
    {
        $conta = $this->contaBb();
        $fornecedor = Fornecedor::create([
            'nome' => 'KASA TUA DECORACOES',
            'status' => 1,
        ]);

        $this->postOfx($conta)->assertCreated();

        $transacao = ConciliacaoBancariaTransacao::query()
            ->where('fit_id', '2026061511710450')
            ->where('conta_financeira_id', $conta->id)
            ->firstOrFail();

        $this->patchJson("/api/v1/financeiro/conciliacao-bancaria/transacoes/{$transacao->id}/candidato", [
            'candidato_tipo' => 'fornecedor_provavel',
            'candidato_id' => $fornecedor->id,
            'forma_pagamento' => 'PIX',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pendente')
            ->assertJsonPath('data.candidato_tipo', null)
            ->assertJsonPath('data.candidato_id', null)
            ->assertJsonPath('data.candidato.0.tipo', 'fornecedor_provavel')
            ->assertJsonPath('data.candidato.0.id', $fornecedor->id)
            ->assertJsonPath('data.candidato.0.confirmavel', false);

        $transacao->refresh();
        $this->assertSame('pendente', $transacao->status);
        $this->assertNull($transacao->candidato_tipo);
        $this->assertNull($transacao->candidato_id);
        $this->assertSame('PIX', $transacao->forma_pagamento);
        $this->assertSame('fornecedor_provavel', $transacao->candidato_json[0]['tipo']);
        $this->assertSame($fornecedor->id, $transacao->candidato_json[0]['id']);
    }

    public function test_confirmacao_com_lancamento_financeiro_existente_apenas_vincula(): void
    {
        $conta = $this->contaBb();
        $lancamento = LancamentoFinanceiro::create([
            'descricao' => 'Despesa KASA ja lancada',
            'tipo' => 'despesa',
            'status' => 'confirmado',
            'conta_id' => $conta->id,
            'valor' => 17104.50,
            'data_movimento' => '2026-06-15 00:00:00',
            'data_pagamento' => '2026-06-15 00:00:00',
            'competencia' => '2026-06-01',
            'created_by' => $this->usuario->id,
        ]);

        $this->postOfx($conta)->assertCreated();

        $transacao = ConciliacaoBancariaTransacao::query()
            ->where('fit_id', '2026061511710450')
            ->where('conta_financeira_id', $conta->id)
            ->firstOrFail();

        $this->patchJson("/api/v1/financeiro/conciliacao-bancaria/transacoes/{$transacao->id}/candidato", [
            'candidato_tipo' => 'lancamento_financeiro',
            'candidato_id' => $lancamento->id,
            'forma_pagamento' => 'PIX',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'sugerido')
            ->assertJsonPath('data.candidato_tipo', 'lancamento_financeiro')
            ->assertJsonPath('data.candidato_id', $lancamento->id);

        $this->postJson("/api/v1/financeiro/conciliacao-bancaria/transacoes/{$transacao->id}/confirmar")
            ->assertOk()
            ->assertJsonPath('data.status', 'conciliado')
            ->assertJsonPath('data.lancamento_financeiro_id', $lancamento->id);

        $this->assertSame(0, ContaPagarPagamento::query()->count());
        $this->assertSame($lancamento->id, (int) $transacao->fresh()->lancamento_financeiro_id);
    }

    private function contaBb(): ContaFinanceira
    {
        return ContaFinanceira::create([
            'nome' => 'Banco do Brasil',
            'tipo' => 'banco',
            'banco_nome' => 'Banco do Brasil S/A',
            'banco_codigo' => '001',
            'conta' => '106263',
            'conta_dv' => '8',
            'moeda' => 'BRL',
            'ativo' => true,
            'saldo_inicial' => 0,
        ]);
    }

    private function contaPagarKasa(): ContaPagar
    {
        $fornecedor = Fornecedor::create([
            'nome' => 'KASA TUA DECORACOES',
            'status' => 1,
        ]);

        return ContaPagar::create([
            'fornecedor_id' => $fornecedor->id,
            'descricao' => 'Compra KASA TUA DECORACOES',
            'data_vencimento' => '2026-06-15',
            'valor_bruto' => 17104.50,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'ABERTA',
            'forma_pagamento' => 'PIX',
        ]);
    }

    private function historicoKasaPago(): ContaPagar
    {
        $fornecedor = Fornecedor::create([
            'nome' => 'KASA TUA DECORACOES',
            'status' => 1,
        ]);

        return ContaPagar::create([
            'fornecedor_id' => $fornecedor->id,
            'descricao' => 'Compra antiga KASA TUA DECORACOES',
            'data_vencimento' => '2026-04-15',
            'valor_bruto' => 17104.50,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'PAGA',
            'forma_pagamento' => 'PIX',
        ]);
    }

    private function postOfx(ContaFinanceira $conta)
    {
        $content = (string) file_get_contents(base_path('tests/Fixtures/banco-brasil-conciliacao.ofx'));

        return $this->post('/api/v1/financeiro/conciliacao-bancaria/ofx', [
            'conta_financeira_id' => $conta->id,
            'arquivo' => UploadedFile::fake()->createWithContent('bb.ofx', $content),
        ], [
            'Accept' => 'application/json',
        ]);
    }
}
