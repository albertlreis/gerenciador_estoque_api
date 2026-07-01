<?php

namespace Tests\Feature;

use App\Enums\ContaStatus;
use App\Enums\LancamentoStatus;
use App\Enums\LancamentoTipo;
use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Integrations\ContaAzul\Models\ContaAzulToken;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ContaAzulExportDispatchService;
use App\Integrations\ContaAzul\Services\ExportacaoContaAzulService;
use App\Jobs\ContaAzul\EstornarBaixaContaAzulJob;
use App\Jobs\ContaAzul\ExportBaixaContaAzulJob;
use App\Jobs\ContaAzul\ExportTituloContaAzulJob;
use App\Models\ContaFinanceira;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\LancamentoFinanceiro;
use App\Services\AuditoriaLogService;
use App\Services\ContaReceberCommandService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ContaReceberContaAzulExportTest extends TestCase
{
    /** @var array<int, int> */
    private array $contaReceberIds = [];

    /** @var array<int, int> */
    private array $pagamentoIds = [];

    /** @var array<int, int> */
    private array $contaFinanceiraIds = [];

    /** @var array<int, int> */
    private array $mapeamentoIds = [];

    /** @var array<int, int> */
    private array $conexaoIds = [];

    protected function tearDown(): void
    {
        if ($this->mapeamentoIds !== []) {
            ContaAzulMapeamento::query()
                ->whereIn('id', $this->mapeamentoIds)
                ->delete();
        }

        if ($this->pagamentoIds !== []) {
            LancamentoFinanceiro::query()
                ->where('pagamento_type', ContaReceberPagamento::class)
                ->whereIn('pagamento_id', $this->pagamentoIds)
                ->forceDelete();

            DB::table('auditoria_logs')
                ->where(function ($query): void {
                    $query
                        ->where(function ($q): void {
                            $q->where('entity_type', 'baixa')
                                ->whereIn('entity_id', array_map('strval', $this->pagamentoIds));
                        })
                        ->orWhere(function ($q): void {
                            $q->where('entity_type', ContaReceberPagamento::class)
                                ->whereIn('entity_id', array_map('strval', $this->pagamentoIds));
                        });
                })
                ->delete();

            ContaReceberPagamento::query()
                ->whereIn('id', $this->pagamentoIds)
                ->forceDelete();
        }

        if ($this->contaReceberIds !== []) {
            DB::table('auditoria_logs')
                ->where(function ($query): void {
                    $query
                        ->where(function ($q): void {
                            $q->where('entity_type', ContaReceber::class)
                                ->whereIn('entity_id', array_map('strval', $this->contaReceberIds));
                        })
                        ->orWhere(function ($q): void {
                            $q->where('entity_type', 'titulo')
                                ->whereIn('entity_id', array_map('strval', $this->contaReceberIds));
                        });
                })
                ->delete();

            ContaReceber::withTrashed()
                ->whereIn('id', $this->contaReceberIds)
                ->forceDelete();
        }

        if ($this->contaFinanceiraIds !== []) {
            ContaFinanceira::query()
                ->whereIn('id', $this->contaFinanceiraIds)
                ->delete();
        }

        if ($this->conexaoIds !== []) {
            ContaAzulConexao::query()
                ->whereIn('id', $this->conexaoIds)
                ->delete();
        }

        Mockery::close();

        parent::tearDown();
    }

    public function test_registrar_pagamento_nao_falha_quando_exportacao_baixa_conta_azul_falha(): void
    {
        Log::spy();

        $conta = $this->criarContaReceber('Pagamento Conta Azul Nao Impeditiva', '150.00');
        $contaFinanceira = $this->criarContaFinanceira('Banco Exportacao Baixa');

        $exports = Mockery::mock(ContaAzulExportDispatchService::class);
        $exports->shouldReceive('baixa')
            ->once()
            ->with(Mockery::type('int'), null, Mockery::on(
                fn (array $contexto) => ($contexto['evento'] ?? null) === 'pagamento_registrado'
            ))
            ->andThrow(new RuntimeException('Conta Azul indisponivel para baixa'));

        $this->app->instance(ContaAzulExportDispatchService::class, $exports);

        $pagamento = app(ContaReceberCommandService::class)->registrarPagamento($conta, [
            'data_pagamento' => '2026-06-19',
            'valor' => '100.00',
            'forma_pagamento' => 'PIX',
            'conta_financeira_id' => $contaFinanceira->id,
            'observacoes' => 'Pagamento local deve persistir',
        ]);
        $this->pagamentoIds[] = (int) $pagamento->id;

        $this->assertDatabaseHas('contas_receber_pagamentos', [
            'id' => $pagamento->id,
            'conta_receber_id' => $conta->id,
            'valor' => '100.00',
            'forma_pagamento' => 'PIX',
        ]);

        $this->assertDatabaseHas('lancamentos_financeiros', [
            'pagamento_type' => ContaReceberPagamento::class,
            'pagamento_id' => $pagamento->id,
            'tipo' => LancamentoTipo::RECEITA->value,
            'status' => LancamentoStatus::CONFIRMADO->value,
            'valor' => '100.00',
        ]);

        $conta->refresh();
        $this->assertSame(ContaStatus::PARCIAL->value, $conta->status->value);
        $this->assertSame(100.00, (float) $conta->valor_recebido);
        $this->assertSame(50.00, (float) $conta->saldo_aberto);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Falha ao disparar exportacao Conta Azul para baixa.', Mockery::on(
                fn (array $contexto) => ($contexto['pagamento_id'] ?? null) === $pagamento->id
                    && ($contexto['evento'] ?? null) === 'pagamento_registrado'
                    && ($contexto['erro'] ?? null) === 'Conta Azul indisponivel para baixa'
            ));
    }

    public function test_criar_conta_com_pagamento_inicial_persiste_quando_exportacao_conta_azul_falha(): void
    {
        Log::spy();

        $contaFinanceira = $this->criarContaFinanceira('Banco Exportacao Inicial');

        $exports = Mockery::mock(ContaAzulExportDispatchService::class);
        $exports->shouldReceive('baixa')
            ->once()
            ->andThrow(new RuntimeException('Fila Conta Azul indisponivel para baixa'));
        $exports->shouldReceive('titulo')
            ->never();

        $this->app->instance(ContaAzulExportDispatchService::class, $exports);

        $conta = app(ContaReceberCommandService::class)->criar([
            'descricao' => 'Conta Inicial Conta Azul Nao Impeditiva',
            'data_emissao' => '2026-06-19',
            'data_vencimento' => '2026-06-30',
            'valor_bruto' => '200.00',
            'pagamento_inicial' => [
                'data_pagamento' => '2026-06-19',
                'valor' => '200.00',
                'forma_pagamento' => 'PIX',
                'conta_financeira_id' => $contaFinanceira->id,
            ],
        ]);
        $this->contaReceberIds[] = (int) $conta->id;

        $pagamentoId = (int) ContaReceberPagamento::query()
            ->where('conta_receber_id', $conta->id)
            ->value('id');
        $this->pagamentoIds[] = $pagamentoId;

        $this->assertDatabaseHas('contas_receber', [
            'id' => $conta->id,
            'descricao' => 'Conta Inicial Conta Azul Nao Impeditiva',
            'status' => ContaStatus::PAGA->value,
        ]);
        $this->assertDatabaseHas('contas_receber_pagamentos', [
            'id' => $pagamentoId,
            'conta_receber_id' => $conta->id,
            'valor' => '200.00',
        ]);

        Log::shouldHaveReceived('warning')
            ->once();
    }

    public function test_pagamento_sem_titulo_mapeado_enfileira_titulo_antes_da_baixa(): void
    {
        Queue::fake();
        $this->criarConexaoContaAzul();

        $conta = $this->criarContaReceber('Pagamento Conta Azul Encadeado', '150.00');
        $contaFinanceira = $this->criarContaFinanceira('Banco Exportacao Encadeada');

        $pagamento = app(ContaReceberCommandService::class)->registrarPagamento($conta, [
            'data_pagamento' => '2026-06-19',
            'valor' => '100.00',
            'forma_pagamento' => 'PIX',
            'conta_financeira_id' => $contaFinanceira->id,
        ]);
        $this->pagamentoIds[] = (int) $pagamento->id;

        Queue::assertPushedWithChain(
            ExportTituloContaAzulJob::class,
            [ExportBaixaContaAzulJob::class],
            fn (ExportTituloContaAzulJob $job) => $job->contaReceberId === (int) $conta->id
        );

        Queue::assertPushed(ExportTituloContaAzulJob::class, function (ExportTituloContaAzulJob $job) use ($pagamento) {
            $chain = collect($job->chained)
                ->map(fn (string $serialized) => unserialize($serialized))
                ->values();

            return $chain->count() === 1
                && $chain[0] instanceof ExportBaixaContaAzulJob
                && $chain[0]->pagamentoId === (int) $pagamento->id;
        });
        Queue::assertNotPushed(ExportBaixaContaAzulJob::class);
    }

    public function test_pagamento_com_titulo_mapeado_enfileira_somente_baixa(): void
    {
        Queue::fake();
        $this->criarConexaoContaAzul();

        $conta = $this->criarContaReceber('Pagamento Conta Azul Mapeado', '150.00');
        $contaFinanceira = $this->criarContaFinanceira('Banco Exportacao Mapeada');
        $this->mapearTitulo($conta, 'titulo-externo-1');

        $pagamento = app(ContaReceberCommandService::class)->registrarPagamento($conta, [
            'data_pagamento' => '2026-06-19',
            'valor' => '100.00',
            'forma_pagamento' => 'PIX',
            'conta_financeira_id' => $contaFinanceira->id,
        ]);
        $this->pagamentoIds[] = (int) $pagamento->id;

        Queue::assertPushedWithoutChain(
            ExportBaixaContaAzulJob::class,
            fn (ExportBaixaContaAzulJob $job) => $job->pagamentoId === (int) $pagamento->id
        );
        Queue::assertNotPushed(ExportTituloContaAzulJob::class);
    }

    public function test_criar_conta_com_pagamento_inicial_enfileira_um_titulo_encadeado_com_baixa(): void
    {
        Queue::fake();
        $this->criarConexaoContaAzul();

        $contaFinanceira = $this->criarContaFinanceira('Banco Exportacao Inicial Encadeada');

        $conta = app(ContaReceberCommandService::class)->criar([
            'descricao' => 'Conta Inicial Conta Azul Encadeada',
            'data_emissao' => '2026-06-19',
            'data_vencimento' => '2026-06-30',
            'valor_bruto' => '200.00',
            'pagamento_inicial' => [
                'data_pagamento' => '2026-06-19',
                'valor' => '200.00',
                'forma_pagamento' => 'PIX',
                'conta_financeira_id' => $contaFinanceira->id,
            ],
        ]);
        $this->contaReceberIds[] = (int) $conta->id;

        $pagamentoId = (int) ContaReceberPagamento::query()
            ->where('conta_receber_id', $conta->id)
            ->value('id');
        $this->pagamentoIds[] = $pagamentoId;

        Queue::assertPushed(ExportTituloContaAzulJob::class, 1);
        Queue::assertPushedWithChain(
            ExportTituloContaAzulJob::class,
            [ExportBaixaContaAzulJob::class],
            fn (ExportTituloContaAzulJob $job) => $job->contaReceberId === (int) $conta->id
        );
        Queue::assertNotPushed(ExportBaixaContaAzulJob::class);
    }

    public function test_exportar_baixa_receber_resolve_parcela_do_titulo_antes_de_baixar(): void
    {
        $conexao = $this->criarConexaoContaAzul();
        $conta = $this->criarContaReceber('Baixa Receber Resolve Parcela', '100.00');
        $contaFinanceira = $this->criarContaFinanceira('Banco Baixa Receber Resolve Parcela');
        $this->mapearTitulo($conta, 'evento-receber-ext-1');

        $pagamento = ContaReceberPagamento::create([
            'conta_receber_id' => $conta->id,
            'data_pagamento' => '2026-06-20',
            'valor' => '100.00',
            'forma_pagamento' => 'PIX',
            'conta_financeira_id' => $contaFinanceira->id,
        ]);
        $this->pagamentoIds[] = (int) $pagamento->id;

        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldReceive('get')
            ->once()
            ->with('v1/financeiro/eventos-financeiros/evento-receber-ext-1/parcelas', 'token-valido')
            ->andReturn([
                'status' => 200,
                'body' => '[{"id":"parcela-receber-ext-1"}]',
                'json' => [['id' => 'parcela-receber-ext-1']],
                'headers' => [],
            ]);
        $client->shouldReceive('post')
            ->once()
            ->with(
                'v1/financeiro/eventos-financeiros/parcelas/parcela-receber-ext-1/baixa',
                'token-valido',
                Mockery::on(function (array $payload): bool {
                    $this->assertEqualsWithDelta(100.0, $payload['valor'], 0.001);
                    $this->assertSame('2026-06-20', $payload['data_pagamento']);
                    $this->assertSame('PIX', $payload['forma_pagamento']);

                    return true;
                })
            )
            ->andReturn([
                'status' => 201,
                'body' => '{"id":"baixa-receber-ext-1"}',
                'json' => ['id' => 'baixa-receber-ext-1'],
                'headers' => [],
            ]);
        $this->app->instance(ContaAzulClient::class, $client);

        app(ExportacaoContaAzulService::class)->exportarBaixa($conexao, $pagamento);

        $this->mapeamentoIds[] = (int) ContaAzulMapeamento::query()
            ->where('tipo_entidade', ContaAzulEntityType::BAIXA)
            ->where('id_local', $pagamento->id)
            ->value('id');

        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::BAIXA,
            'id_local' => $pagamento->id,
            'id_externo' => 'baixa-receber-ext-1',
        ]);
    }

    public function test_excluir_conta_receber_com_pagamento_sem_confirmacao_retorna_422(): void
    {
        $conta = $this->criarContaReceber('Receber Exclusao Sem Confirmacao', '100.00');
        $contaFinanceira = $this->criarContaFinanceira('Banco Receber Exclusao Sem Confirmacao');

        $pagamento = app(ContaReceberCommandService::class)->registrarPagamento($conta, [
            'data_pagamento' => '2026-06-20',
            'valor' => '100.00',
            'forma_pagamento' => 'PIX',
            'conta_financeira_id' => $contaFinanceira->id,
        ]);
        $this->pagamentoIds[] = (int) $pagamento->id;

        try {
            app(ContaReceberCommandService::class)->deletar($conta);
            $this->fail('A exclusao deveria exigir confirmacao dos estornos.');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $payload = json_decode($e->getResponse()->getContent(), true);
            $this->assertSame('confirmacao_estornos_obrigatoria', $payload['reason']);
            $this->assertSame($pagamento->id, $payload['pagamentos'][0]['id']);
        }

        $this->assertDatabaseHas('contas_receber', ['id' => $conta->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('contas_receber_pagamentos', ['id' => $pagamento->id]);
    }

    public function test_excluir_conta_receber_com_confirmacao_estorna_pagamentos_cancela_ledger_e_audita(): void
    {
        $conta = $this->criarContaReceber('Receber Exclusao Confirmada', '100.00');
        $contaFinanceira = $this->criarContaFinanceira('Banco Receber Exclusao Confirmada');

        $pagamento = app(ContaReceberCommandService::class)->registrarPagamento($conta, [
            'data_pagamento' => '2026-06-20',
            'valor' => '100.00',
            'forma_pagamento' => 'PIX',
            'conta_financeira_id' => $contaFinanceira->id,
        ]);
        $pagamentoId = (int) $pagamento->id;
        $this->pagamentoIds[] = $pagamentoId;

        $exports = Mockery::mock(ContaAzulExportDispatchService::class);
        $exports->shouldReceive('estornarBaixa')
            ->once()
            ->with(ContaAzulEntityType::BAIXA, $pagamentoId, null, Mockery::on(
                fn (array $contexto) => ($contexto['evento'] ?? null) === 'exclusao_conta_receber'
            ));
        $exports->shouldReceive('excluirTituloFinanceiro')
            ->once()
            ->with(ContaAzulEntityType::TITULO, (int) $conta->id, null, Mockery::on(
                fn (array $contexto) => ($contexto['evento'] ?? null) === 'exclusao_conta_receber'
            ));
        $this->app->instance(ContaAzulExportDispatchService::class, $exports);

        app(ContaReceberCommandService::class)->deletar($conta, true);

        $this->assertSoftDeleted('contas_receber', ['id' => $conta->id]);
        $this->assertDatabaseMissing('contas_receber_pagamentos', ['id' => $pagamentoId]);
        $this->assertDatabaseHas('lancamentos_financeiros', [
            'pagamento_type' => ContaReceberPagamento::class,
            'pagamento_id' => $pagamentoId,
            'status' => LancamentoStatus::CANCELADO->value,
        ]);
        $this->assertDatabaseHas('auditoria_logs', [
            'modulo' => 'financeiro',
            'acao' => 'reversed_by_delete',
            'entity_type' => ContaReceberPagamento::class,
            'entity_id' => (string) $pagamentoId,
        ]);
        $this->assertDatabaseHas('auditoria_logs', [
            'modulo' => 'financeiro',
            'acao' => 'deleted',
            'entity_type' => ContaReceber::class,
            'entity_id' => (string) $conta->id,
        ]);
    }

    public function test_job_estorna_baixa_receber_mapeada_e_trata_404_como_idempotente(): void
    {
        $this->criarConexaoContaAzul();
        $conta = $this->criarContaReceber('Receber Estorno Baixa Externa', '100.00');
        $contaFinanceira = $this->criarContaFinanceira('Banco Receber Estorno Baixa Externa');

        $pagamento = ContaReceberPagamento::create([
            'conta_receber_id' => $conta->id,
            'data_pagamento' => '2026-06-20',
            'valor' => '100.00',
            'forma_pagamento' => 'PIX',
            'conta_financeira_id' => $contaFinanceira->id,
        ]);
        $this->pagamentoIds[] = (int) $pagamento->id;

        $mapeamento = ContaAzulMapeamento::create([
            'tipo_entidade' => ContaAzulEntityType::BAIXA,
            'id_local' => $pagamento->id,
            'id_externo' => 'baixa-receber-ext-404',
            'origem_inicial' => 'test',
            'sincronizado_em' => now(),
        ]);
        $this->mapeamentoIds[] = (int) $mapeamento->id;

        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldReceive('delete')
            ->once()
            ->with('v1/financeiro/eventos-financeiros/parcelas/baixa/baixa-receber-ext-404', 'token-valido')
            ->andReturn([
                'status' => 404,
                'body' => '{"message":"not found"}',
                'json' => ['message' => 'not found'],
                'headers' => [],
            ]);
        $this->app->instance(ContaAzulClient::class, $client);

        (new EstornarBaixaContaAzulJob(ContaAzulEntityType::BAIXA, (int) $pagamento->id))->handle(
            app(ExportacaoContaAzulService::class),
            app(ContaAzulConnectionService::class),
            app(AuditoriaLogService::class)
        );

        $this->assertDatabaseMissing('conta_azul_mapeamentos', ['id' => $mapeamento->id]);
        $this->assertDatabaseHas('auditoria_logs', [
            'modulo' => 'conta_azul',
            'acao' => 'estorno_baixa',
            'status' => 'sucesso',
            'entity_type' => ContaAzulEntityType::BAIXA,
            'entity_id' => (string) $pagamento->id,
        ]);
    }

    public function test_job_de_baixa_registra_auditoria_quando_exportacao_falha(): void
    {
        $conta = $this->criarContaReceber('Job Baixa Conta Azul Nao Impeditiva', '100.00');
        $contaFinanceira = $this->criarContaFinanceira('Banco Exportacao Job');

        $pagamento = ContaReceberPagamento::create([
            'conta_receber_id' => $conta->id,
            'data_pagamento' => '2026-06-19',
            'valor' => '100.00',
            'forma_pagamento' => 'PIX',
            'conta_financeira_id' => $contaFinanceira->id,
        ]);
        $this->pagamentoIds[] = (int) $pagamento->id;

        $conexao = new ContaAzulConexao([
            'id' => 10,
            'status' => 'ativa',
            'ambiente' => 'homologacao',
        ]);

        $connections = Mockery::mock(ContaAzulConnectionService::class);
        $connections->shouldReceive('latestForLoja')
            ->once()
            ->with(null)
            ->andReturn($conexao);

        $export = Mockery::mock(ExportacaoContaAzulService::class);
        $export->shouldReceive('exportarBaixa')
            ->once()
            ->with($conexao, Mockery::type(ContaReceberPagamento::class), null)
            ->andThrow(new ContaAzulException('Titulo local ainda nao possui id externo'));

        $this->expectException(ContaAzulException::class);

        try {
            (new ExportBaixaContaAzulJob($pagamento->id))->handle(
                $export,
                $connections,
                app(AuditoriaLogService::class)
            );
        } finally {
            $this->assertDatabaseHas('auditoria_logs', [
                'modulo' => 'conta_azul',
                'acao' => 'export',
                'status' => 'falha',
                'entity_type' => 'baixa',
                'entity_id' => (string) $pagamento->id,
                'message' => 'Titulo local ainda nao possui id externo',
            ]);

            $this->assertDatabaseHas('contas_receber_pagamentos', [
                'id' => $pagamento->id,
                'conta_receber_id' => $conta->id,
                'valor' => '100.00',
            ]);
        }
    }

    private function criarContaReceber(string $descricao, string $valor): ContaReceber
    {
        $conta = ContaReceber::create([
            'descricao' => $descricao,
            'data_emissao' => '2026-06-19',
            'data_vencimento' => '2026-06-30',
            'valor_bruto' => $valor,
            'valor_liquido' => $valor,
            'valor_recebido' => '0.00',
            'saldo_aberto' => $valor,
            'status' => ContaStatus::ABERTA->value,
        ]);

        $this->contaReceberIds[] = (int) $conta->id;

        return $conta;
    }

    private function criarContaFinanceira(string $nome): ContaFinanceira
    {
        $conta = ContaFinanceira::create([
            'nome' => $nome . ' ' . uniqid(),
            'slug' => 'conta-azul-export-' . uniqid(),
            'tipo' => 'banco',
            'ativo' => true,
        ]);

        $this->contaFinanceiraIds[] = (int) $conta->id;

        return $conta;
    }

    private function criarConexaoContaAzul(): ContaAzulConexao
    {
        $conexao = ContaAzulConexao::create([
            'status' => 'ativa',
            'ambiente' => 'homologacao',
        ]);
        $this->conexaoIds[] = (int) $conexao->id;

        ContaAzulToken::create([
            'conexao_id' => $conexao->id,
            'access_token' => 'token-valido',
            'refresh_token' => 'refresh-valido',
            'expires_at' => now()->addHour(),
        ]);

        return $conexao;
    }

    private function mapearTitulo(ContaReceber $conta, string $idExterno): ContaAzulMapeamento
    {
        $mapeamento = ContaAzulMapeamento::create([
            'tipo_entidade' => ContaAzulEntityType::TITULO,
            'id_local' => $conta->id,
            'id_externo' => $idExterno,
            'origem_inicial' => 'test',
            'sincronizado_em' => now(),
        ]);
        $this->mapeamentoIds[] = (int) $mapeamento->id;

        return $mapeamento;
    }
}
