<?php

namespace Tests\Feature;

use App\Enums\ContaStatus;
use App\Enums\LancamentoStatus;
use App\Enums\LancamentoTipo;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Integrations\ContaAzul\Models\ContaAzulToken;
use App\Integrations\ContaAzul\Services\ContaAzulExportDispatchService;
use App\Jobs\ContaAzul\ExportBaixaContaPagarContaAzulJob;
use App\Jobs\ContaAzul\ExportContaPagarContaAzulJob;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\LancamentoFinanceiro;
use App\Services\ContaPagarCommandService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ContaPagarContaAzulExportTest extends TestCase
{
    /** @var array<int, int> */
    private array $contaPagarIds = [];

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
            ContaAzulMapeamento::query()->whereIn('id', $this->mapeamentoIds)->delete();
        }

        if ($this->pagamentoIds !== []) {
            LancamentoFinanceiro::query()
                ->where('pagamento_type', ContaPagarPagamento::class)
                ->whereIn('pagamento_id', $this->pagamentoIds)
                ->forceDelete();

            DB::table('auditoria_logs')
                ->where(function ($query): void {
                    $query
                        ->where(function ($q): void {
                            $q->whereIn('entity_type', [ContaAzulEntityType::BAIXA_CONTA_PAGAR, ContaPagarPagamento::class])
                                ->whereIn('entity_id', array_map('strval', $this->pagamentoIds));
                        });
                })
                ->delete();

            ContaPagarPagamento::query()
                ->whereIn('id', $this->pagamentoIds)
                ->delete();
        }

        if ($this->contaPagarIds !== []) {
            DB::table('auditoria_logs')
                ->where(function ($query): void {
                    $query
                        ->where(function ($q): void {
                            $q->whereIn('entity_type', [ContaPagar::class, ContaAzulEntityType::CONTA_PAGAR])
                                ->whereIn('entity_id', array_map('strval', $this->contaPagarIds));
                        });
                })
                ->delete();

            ContaPagar::withTrashed()
                ->whereIn('id', $this->contaPagarIds)
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

    public function test_criar_conta_pagar_enfileira_exportacao_conta_azul_quando_ha_conexao(): void
    {
        Queue::fake();
        $this->criarConexaoContaAzul();

        $resource = app(ContaPagarCommandService::class)->criar([
            'descricao' => 'Conta Pagar Conta Azul Export',
            'data_emissao' => '2026-06-19',
            'data_vencimento' => '2026-06-30',
            'valor_bruto' => '200.00',
        ]);

        $contaId = (int) $resource->resource->id;
        $this->contaPagarIds[] = $contaId;

        Queue::assertPushed(
            ExportContaPagarContaAzulJob::class,
            fn (ExportContaPagarContaAzulJob $job) => $job->contaPagarId === $contaId
        );
    }

    public function test_criar_conta_pagar_sem_conexao_registra_ignorado_e_nao_quebra(): void
    {
        Queue::fake();
        DB::table('conta_azul_conexoes')->delete();

        $resource = app(ContaPagarCommandService::class)->criar([
            'descricao' => 'Conta Pagar Sem Conexao Conta Azul',
            'data_emissao' => '2026-06-19',
            'data_vencimento' => '2026-06-30',
            'valor_bruto' => '150.00',
        ]);

        $contaId = (int) $resource->resource->id;
        $this->contaPagarIds[] = $contaId;

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('contas_pagar', [
            'id' => $contaId,
            'descricao' => 'Conta Pagar Sem Conexao Conta Azul',
        ]);
        $this->assertDatabaseHas('auditoria_logs', [
            'modulo' => 'conta_azul',
            'acao' => 'export',
            'entity_type' => ContaAzulEntityType::CONTA_PAGAR,
            'entity_id' => (string) $contaId,
            'status' => 'ignorado',
            'source_kind' => 'sync',
        ]);
    }

    public function test_pagamento_sem_conta_pagar_mapeada_enfileira_conta_antes_da_baixa(): void
    {
        Queue::fake();
        $this->criarConexaoContaAzul();

        $conta = $this->criarContaPagar('Conta Pagar Baixa Encadeada', '300.00');
        $contaFinanceira = $this->criarContaFinanceira('Banco Baixa Pagar Encadeada');

        $pagamento = app(ContaPagarCommandService::class)->registrarPagamento($conta, [
            'data_pagamento' => '2026-06-20',
            'valor' => '120.00',
            'forma_pagamento' => 'PIX',
            'conta_financeira_id' => $contaFinanceira->id,
        ]);
        $pagamentoId = (int) $pagamento->resource->id;
        $this->pagamentoIds[] = $pagamentoId;

        Queue::assertPushedWithChain(
            ExportContaPagarContaAzulJob::class,
            [ExportBaixaContaPagarContaAzulJob::class],
            fn (ExportContaPagarContaAzulJob $job) => $job->contaPagarId === (int) $conta->id
        );
        Queue::assertNotPushed(ExportBaixaContaPagarContaAzulJob::class);
    }

    public function test_pagamento_persiste_quando_disparo_baixa_conta_azul_falha(): void
    {
        Log::spy();

        $conta = $this->criarContaPagar('Conta Pagar Baixa Best Effort', '300.00');
        $contaFinanceira = $this->criarContaFinanceira('Banco Baixa Pagar Best Effort');

        $exports = Mockery::mock(ContaAzulExportDispatchService::class);
        $exports->shouldReceive('baixaContaPagar')
            ->once()
            ->andThrow(new RuntimeException('Fila Conta Azul indisponivel para baixa pagar'));

        $this->app->instance(ContaAzulExportDispatchService::class, $exports);

        $pagamento = app(ContaPagarCommandService::class)->registrarPagamento($conta, [
            'data_pagamento' => '2026-06-20',
            'valor' => '120.00',
            'forma_pagamento' => 'PIX',
            'conta_financeira_id' => $contaFinanceira->id,
        ]);
        $pagamentoId = (int) $pagamento->resource->id;
        $this->pagamentoIds[] = $pagamentoId;

        $this->assertDatabaseHas('contas_pagar_pagamentos', [
            'id' => $pagamentoId,
            'conta_pagar_id' => $conta->id,
            'valor' => '120.00',
        ]);
        $this->assertDatabaseHas('lancamentos_financeiros', [
            'pagamento_type' => ContaPagarPagamento::class,
            'pagamento_id' => $pagamentoId,
            'tipo' => LancamentoTipo::DESPESA->value,
            'status' => LancamentoStatus::CONFIRMADO->value,
        ]);

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_backfill_dry_run_lista_pendentes_e_ignora_mapeadas_sem_force(): void
    {
        $pendente = $this->criarContaPagar('Conta Pagar Backfill Pendente', '100.00');
        $mapeada = $this->criarContaPagar('Conta Pagar Backfill Mapeada', '100.00');
        $this->mapearContaPagar($mapeada, 'pagar-ext-ja-existe');

        $exitCode = Artisan::call('conta-azul:exportar-contas-pagar-pendentes', [
            '--dry-run' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("dry-run conta_pagar #{$pendente->id}", $output);
        $this->assertStringContainsString("ignorada conta_pagar #{$mapeada->id}", $output);
    }

    private function criarContaPagar(string $descricao, string $valor): ContaPagar
    {
        $conta = ContaPagar::create([
            'descricao' => $descricao,
            'data_emissao' => '2026-06-19',
            'data_vencimento' => '2026-06-30',
            'valor_bruto' => $valor,
            'desconto' => '0.00',
            'juros' => '0.00',
            'multa' => '0.00',
            'status' => ContaStatus::ABERTA->value,
        ]);

        $this->contaPagarIds[] = (int) $conta->id;

        return $conta;
    }

    private function criarContaFinanceira(string $nome): ContaFinanceira
    {
        $conta = ContaFinanceira::create([
            'nome' => $nome . ' ' . uniqid(),
            'slug' => 'conta-azul-pagar-export-' . uniqid(),
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

    private function mapearContaPagar(ContaPagar $conta, string $idExterno): ContaAzulMapeamento
    {
        $mapeamento = ContaAzulMapeamento::create([
            'tipo_entidade' => ContaAzulEntityType::CONTA_PAGAR,
            'id_local' => $conta->id,
            'id_externo' => $idExterno,
            'origem_inicial' => 'test',
            'sincronizado_em' => now(),
        ]);
        $this->mapeamentoIds[] = (int) $mapeamento->id;

        return $mapeamento;
    }
}

