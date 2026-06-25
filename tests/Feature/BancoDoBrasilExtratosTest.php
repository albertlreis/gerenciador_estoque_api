<?php

namespace Tests\Feature;

use App\Integrations\Bancos\BancoDoBrasil\BancoDoBrasilExtratosClient;
use App\Models\ConciliacaoBancariaTransacao;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\Fornecedor;
use App\Models\IntegracaoBancariaConexao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BancoDoBrasilExtratosTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetFinanceiroConciliacaoState();

        $this->usuario = Usuario::create([
            'nome' => 'Usuario BB Extratos',
            'email' => 'bb-extratos-' . uniqid('', true) . '@example.test',
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
            'integracao_bancaria_conexoes',
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

    public function test_status_nao_expoe_segredos_quando_nao_configurado(): void
    {
        config(['banco_do_brasil.extratos.enabled' => false]);
        app()->forgetInstance(BancoDoBrasilExtratosClient::class);

        $this->getJson('/api/v1/integrations/bancos/bb-extratos/status')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('configured', false)
            ->assertJsonMissing(['client_secret'])
            ->assertJsonMissing(['app_key']);
    }

    public function test_sincroniza_extrato_bb_sugere_match_e_nao_duplica_transacoes(): void
    {
        $this->configureBb();
        $this->mockBbHttp();

        $conta = $this->contaBb();
        $titulo = $this->contaPagarKasa();

        $payload = [
            'conta_financeira_id' => $conta->id,
            'data_inicio' => '2026-06-15',
            'data_fim' => '2026-06-16',
        ];

        $this->postJson('/api/v1/financeiro/conciliacao-bancaria/sincronizar-banco', $payload)
            ->assertCreated()
            ->assertJsonPath('data.origem', 'bb_api')
            ->assertJsonPath('data.resumo.total', 2)
            ->assertJsonPath('data.resumo.sugeridas', 1)
            ->assertJsonPath('data.transacoes.0.origem', 'bb_api');

        $this->assertSame(2, ConciliacaoBancariaTransacao::query()->count());
        $this->assertDatabaseHas('conciliacao_bancaria_transacoes', [
            'origem' => 'bb_api',
            'origem_transacao_id' => '987654',
            'status' => 'sugerido',
            'candidato_tipo' => 'conta_pagar',
            'candidato_id' => $titulo->id,
        ]);

        $this->postJson('/api/v1/financeiro/conciliacao-bancaria/sincronizar-banco', $payload)
            ->assertCreated();

        $this->assertSame(2, ConciliacaoBancariaTransacao::query()->count());
        $this->assertDatabaseHas('integracao_bancaria_conexoes', [
            'conta_financeira_id' => $conta->id,
            'provedor' => 'bb_extratos',
            'status' => 'ativa',
            'ultimo_periodo_inicio' => '2026-06-15',
            'ultimo_periodo_fim' => '2026-06-16',
        ]);
    }

    public function test_rejeita_sincronizacao_bb_para_conta_de_outro_banco(): void
    {
        $this->configureBb();

        $conta = ContaFinanceira::create([
            'nome' => 'Outro banco',
            'tipo' => 'banco',
            'banco_codigo' => '237',
            'agencia' => '1',
            'conta' => '999',
            'moeda' => 'BRL',
            'ativo' => true,
            'saldo_inicial' => 0,
        ]);

        $this->postJson('/api/v1/financeiro/conciliacao-bancaria/sincronizar-banco', [
            'conta_financeira_id' => $conta->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['conta_financeira_id']);
    }

    public function test_confirmacao_de_transacao_bb_segue_fluxo_manual_de_baixa(): void
    {
        $this->configureBb();
        $this->mockBbHttp();

        $conta = $this->contaBb();
        $titulo = $this->contaPagarKasa();

        $this->postJson('/api/v1/financeiro/conciliacao-bancaria/sincronizar-banco', [
            'conta_financeira_id' => $conta->id,
            'data_inicio' => '2026-06-15',
            'data_fim' => '2026-06-16',
        ])->assertCreated();

        $this->assertSame(0, ContaPagarPagamento::query()->where('conta_pagar_id', $titulo->id)->count());

        $transacao = ConciliacaoBancariaTransacao::query()
            ->where('origem_transacao_id', '987654')
            ->firstOrFail();

        $this->postJson("/api/v1/financeiro/conciliacao-bancaria/transacoes/{$transacao->id}/confirmar")
            ->assertOk()
            ->assertJsonPath('data.status', 'conciliado');

        $this->assertSame(1, ContaPagarPagamento::query()->where('conta_pagar_id', $titulo->id)->count());
    }

    public function test_erro_oauth_bb_e_sanitizado(): void
    {
        $this->configureBb();
        Http::fake([
            'https://oauth.bb.test/oauth/token' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'client_secret=supersecretvaluewithmanymanycharacters1234567890',
            ], 401),
        ]);

        $conta = $this->contaBb();

        $response = $this->postJson('/api/v1/integrations/bancos/bb-extratos/test-connection', [
            'conta_financeira_id' => $conta->id,
        ])->assertUnprocessable()
            ->assertJsonPath('reason', 'bb_extratos_oauth_error');

        $this->assertStringNotContainsString(
            'supersecretvaluewithmanymanycharacters1234567890',
            $response->getContent()
        );

        $this->assertStringNotContainsString(
            'supersecretvaluewithmanymanycharacters1234567890',
            (string) IntegracaoBancariaConexao::query()->first()?->ultimo_erro
        );
    }

    public function test_comando_sincroniza_contas_bb_e_registra_falha_sem_interromper(): void
    {
        $this->configureBb();

        $ok = $this->contaBb();
        $fail = ContaFinanceira::create([
            'nome' => 'Banco do Brasil Falha',
            'tipo' => 'banco',
            'banco_nome' => 'Banco do Brasil S/A',
            'banco_codigo' => '001',
            'agencia' => '1234',
            'conta' => '999999',
            'conta_dv' => '0',
            'moeda' => 'BRL',
            'ativo' => true,
            'saldo_inicial' => 0,
        ]);
        $this->contaPagarKasa();

        Http::fake(function ($request) use ($fail) {
            $url = (string) $request->url();
            if (str_contains($url, 'oauth.bb.test')) {
                return Http::response(['access_token' => 'token-bb', 'expires_in' => 3600], 200);
            }

            if (str_contains($url, '/conta/' . $fail->conta)) {
                return Http::response(['message' => 'indisponivel'], 500);
            }

            return Http::response($this->bbStatementPayload(), 200);
        });

        $exitCode = Artisan::call('financeiro:sync-bb-extratos', ['--days' => 2]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('integracao_bancaria_conexoes', [
            'conta_financeira_id' => $ok->id,
            'status' => 'ativa',
        ]);
        $this->assertDatabaseHas('integracao_bancaria_conexoes', [
            'conta_financeira_id' => $fail->id,
            'status' => 'erro',
        ]);
    }

    private function configureBb(): void
    {
        config([
            'banco_do_brasil.extratos.enabled' => true,
            'banco_do_brasil.extratos.env' => 'sandbox',
            'banco_do_brasil.extratos.client_id' => 'client-id',
            'banco_do_brasil.extratos.client_secret' => 'client-secret',
            'banco_do_brasil.extratos.app_key' => 'app-key',
            'banco_do_brasil.extratos.oauth_url' => 'https://oauth.bb.test/oauth/token',
            'banco_do_brasil.extratos.base_url' => 'https://api.bb.test',
            'banco_do_brasil.extratos.statement_path' => '/extratos/v1/conta-corrente/agencia/{agencia}/conta/{conta}',
            'banco_do_brasil.extratos.app_key_param' => 'gw-dev-app-key',
            'banco_do_brasil.extratos.scope' => 'extrato-info',
            'banco_do_brasil.extratos.retry_times' => 1,
        ]);
        app()->forgetInstance(BancoDoBrasilExtratosClient::class);
    }

    private function mockBbHttp(): void
    {
        Http::fake([
            'https://oauth.bb.test/oauth/token' => Http::response(['access_token' => 'token-bb', 'expires_in' => 3600], 200),
            'https://api.bb.test/*' => Http::response($this->bbStatementPayload(), 200),
        ]);
    }

    private function bbStatementPayload(): array
    {
        return [
            'saldoFinal' => '76074.52',
            'dataSaldo' => '2026-06-16',
            'lancamentos' => [
                [
                    'numeroSequencialLancamento' => '987654',
                    'dataLancamento' => '2026-06-15',
                    'valorLancamento' => '17104.50',
                    'indicadorSinalLancamento' => 'D',
                    'textoDescricaoHistorico' => 'PIX KASA TUA DECORACOES',
                ],
                [
                    'numeroSequencialLancamento' => '987655',
                    'dataLancamento' => '2026-06-16',
                    'valorLancamento' => '250.00',
                    'indicadorSinalLancamento' => 'C',
                    'textoDescricaoHistorico' => 'PIX RECEBIDO CLIENTE',
                ],
            ],
        ];
    }

    private function contaBb(): ContaFinanceira
    {
        return ContaFinanceira::create([
            'nome' => 'Banco do Brasil',
            'tipo' => 'banco',
            'banco_nome' => 'Banco do Brasil S/A',
            'banco_codigo' => '001',
            'agencia' => '1234',
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
}
