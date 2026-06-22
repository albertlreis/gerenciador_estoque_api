<?php

namespace Tests\Feature;

use App\Enums\ContaStatus;
use App\Enums\LancamentoStatus;
use App\Enums\LancamentoTipo;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Integrations\ContaAzul\Models\ContaAzulToken;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ContaAzulExportDispatchService;
use App\Integrations\ContaAzul\Services\ExportacaoContaAzulService;
use App\Jobs\ContaAzul\ExportBaixaContaAzulJob;
use App\Jobs\ContaAzul\ExportTituloContaAzulJob;
use App\Models\ContaFinanceira;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\LancamentoFinanceiro;
use App\Services\AuditoriaLogService;
use App\Services\ContaReceberCommandService;
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
