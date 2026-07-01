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
use App\Integrations\ContaAzul\Services\ContaAzulExportDispatchService;
use App\Integrations\ContaAzul\Services\ExportacaoContaAzulService;
use App\Jobs\ContaAzul\ExportBaixaContaPagarContaAzulJob;
use App\Jobs\ContaAzul\ExportContaPagarContaAzulJob;
use App\Models\CategoriaFinanceira;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\Fornecedor;
use App\Models\LancamentoFinanceiro;
use App\Services\ContaPagarCommandService;
use Illuminate\Http\Exceptions\HttpResponseException;
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

    /** @var array<int, int> */
    private array $categoriaIds = [];

    /** @var array<int, int> */
    private array $fornecedorIds = [];

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
            ContaAzulMapeamento::query()
                ->where('tipo_entidade', ContaAzulEntityType::CONTA_PAGAR)
                ->whereIn('id_local', $this->contaPagarIds)
                ->delete();

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

        if ($this->fornecedorIds !== []) {
            Fornecedor::withTrashed()
                ->whereIn('id', $this->fornecedorIds)
                ->forceDelete();
        }

        if ($this->categoriaIds !== []) {
            CategoriaFinanceira::query()
                ->whereIn('id', $this->categoriaIds)
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

    public function test_criar_conta_pagar_com_pagamento_inicial_enfileira_conta_encadeada_com_baixa(): void
    {
        Queue::fake();
        $this->criarConexaoContaAzul();

        $contaFinanceira = $this->criarContaFinanceira('Banco Pagar Inicial Encadeada');

        $resource = app(ContaPagarCommandService::class)->criar([
            'descricao' => 'Conta Pagar Inicial Encadeada',
            'data_emissao' => '2026-06-20',
            'data_vencimento' => '2026-06-30',
            'valor_bruto' => '180.00',
            'pagamento_inicial' => [
                'data_pagamento' => '2026-06-20',
                'valor' => '180.00',
                'forma_pagamento' => 'PIX',
                'conta_financeira_id' => $contaFinanceira->id,
            ],
        ]);

        $contaId = (int) $resource->resource->id;
        $this->contaPagarIds[] = $contaId;
        $pagamentoId = (int) ContaPagarPagamento::query()
            ->where('conta_pagar_id', $contaId)
            ->value('id');
        $this->pagamentoIds[] = $pagamentoId;

        Queue::assertPushedWithChain(
            ExportContaPagarContaAzulJob::class,
            [ExportBaixaContaPagarContaAzulJob::class],
            fn (ExportContaPagarContaAzulJob $job) => $job->contaPagarId === $contaId
        );
        Queue::assertPushed(ExportContaPagarContaAzulJob::class, function (ExportContaPagarContaAzulJob $job) use ($pagamentoId) {
            $chain = collect($job->chained)
                ->map(fn (string $serialized) => unserialize($serialized))
                ->values();

            return $chain->count() === 1
                && $chain[0] instanceof ExportBaixaContaPagarContaAzulJob
                && $chain[0]->pagamentoId === $pagamentoId;
        });
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

    public function test_excluir_conta_pagar_com_pagamento_sem_confirmacao_retorna_422(): void
    {
        $conta = $this->criarContaPagar('Conta Pagar Exclusao Sem Confirmacao', '100.00');
        $contaFinanceira = $this->criarContaFinanceira('Banco Pagar Exclusao Sem Confirmacao');

        $pagamento = app(ContaPagarCommandService::class)->registrarPagamento($conta, [
            'data_pagamento' => '2026-06-20',
            'valor' => '100.00',
            'forma_pagamento' => 'PIX',
            'conta_financeira_id' => $contaFinanceira->id,
        ]);
        $pagamentoId = (int) $pagamento->resource->id;
        $this->pagamentoIds[] = $pagamentoId;

        try {
            app(ContaPagarCommandService::class)->deletar($conta);
            $this->fail('A exclusao deveria exigir confirmacao dos estornos.');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $payload = json_decode($e->getResponse()->getContent(), true);
            $this->assertSame('confirmacao_estornos_obrigatoria', $payload['reason']);
            $this->assertSame($pagamentoId, $payload['pagamentos'][0]['id']);
        }

        $this->assertDatabaseHas('contas_pagar', ['id' => $conta->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('contas_pagar_pagamentos', ['id' => $pagamentoId]);
    }

    public function test_excluir_conta_pagar_com_confirmacao_estorna_pagamentos_cancela_ledger_e_audita(): void
    {
        $conta = $this->criarContaPagar('Conta Pagar Exclusao Confirmada', '100.00');
        $contaFinanceira = $this->criarContaFinanceira('Banco Pagar Exclusao Confirmada');

        $pagamento = app(ContaPagarCommandService::class)->registrarPagamento($conta, [
            'data_pagamento' => '2026-06-20',
            'valor' => '100.00',
            'forma_pagamento' => 'PIX',
            'conta_financeira_id' => $contaFinanceira->id,
        ]);
        $pagamentoId = (int) $pagamento->resource->id;
        $this->pagamentoIds[] = $pagamentoId;

        $exports = Mockery::mock(ContaAzulExportDispatchService::class);
        $exports->shouldReceive('estornarBaixa')
            ->once()
            ->with(ContaAzulEntityType::BAIXA_CONTA_PAGAR, $pagamentoId, null, Mockery::on(
                fn (array $contexto) => ($contexto['evento'] ?? null) === 'exclusao_conta_pagar'
            ));
        $exports->shouldReceive('excluirTituloFinanceiro')
            ->once()
            ->with(ContaAzulEntityType::CONTA_PAGAR, (int) $conta->id, null, Mockery::on(
                fn (array $contexto) => ($contexto['evento'] ?? null) === 'exclusao_conta_pagar'
            ));
        $this->app->instance(ContaAzulExportDispatchService::class, $exports);

        app(ContaPagarCommandService::class)->deletar($conta, true);

        $this->assertSoftDeleted('contas_pagar', ['id' => $conta->id]);
        $this->assertDatabaseMissing('contas_pagar_pagamentos', ['id' => $pagamentoId]);
        $this->assertDatabaseHas('lancamentos_financeiros', [
            'pagamento_type' => ContaPagarPagamento::class,
            'pagamento_id' => $pagamentoId,
            'status' => LancamentoStatus::CANCELADO->value,
        ]);
        $this->assertDatabaseHas('auditoria_logs', [
            'modulo' => 'financeiro',
            'acao' => 'reversed_by_delete',
            'entity_type' => ContaPagarPagamento::class,
            'entity_id' => (string) $pagamentoId,
        ]);
        $this->assertDatabaseHas('auditoria_logs', [
            'modulo' => 'financeiro',
            'acao' => 'deleted',
            'entity_type' => ContaPagar::class,
            'entity_id' => (string) $conta->id,
        ]);
    }

    public function test_exportar_conta_pagar_envia_payload_exigido_pela_conta_azul(): void
    {
        $conexao = $this->criarConexaoContaAzul();
        $categoria = $this->criarCategoriaFinanceira('Combustiveis Payload Conta Azul');
        $fornecedor = $this->criarFornecedor('Fornecedor Payload Conta Azul');
        $contaFinanceira = $this->criarContaFinanceira('Conta Financeira Payload Conta Azul');
        $conta = $this->criarContaPagarComCategoriaFornecedor($categoria, $fornecedor);
        $pagamento = ContaPagarPagamento::create([
            'conta_pagar_id' => $conta->id,
            'data_pagamento' => '2026-06-25',
            'valor' => '50.00',
            'forma_pagamento' => 'PIX',
            'conta_financeira_id' => $contaFinanceira->id,
        ]);
        $this->pagamentoIds[] = (int) $pagamento->id;

        $this->mapearEntidade(ContaAzulEntityType::CATEGORIA_FINANCEIRA, (int) $categoria->id, 'categoria-ext-1');
        $this->mapearEntidade(ContaAzulEntityType::FORNECEDOR, (int) $fornecedor->id, 'fornecedor-ext-1');
        $this->mapearEntidade(ContaAzulEntityType::CONTA_FINANCEIRA, (int) $contaFinanceira->id, 'conta-financeira-ext-1');

        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldReceive('post')
            ->once()
            ->with(
                'v1/financeiro/eventos-financeiros/contas-a-pagar',
                'token-valido',
                Mockery::on(function (array $payload): bool {
                    $this->assertSame('Conta Pagar Payload Conta Azul', $payload['descricao']);
                    $this->assertSame('2026-06-25', $payload['data_competencia']);
                    $this->assertSame('Conta Pagar Payload Conta Azul', $payload['observacao']);
                    $this->assertSame('fornecedor-ext-1', $payload['contato']);
                    $this->assertSame('conta-financeira-ext-1', $payload['conta_financeira']);
                    $this->assertSame('categoria-ext-1', $payload['rateio'][0]['id_categoria']);
                    $this->assertEqualsWithDelta(50.0, $payload['rateio'][0]['valor'], 0.001);
                    $this->assertArrayNotHasKey('competenceDate', $payload);
                    $this->assertArrayNotHasKey('idFornecedor', $payload);

                    $parcela = $payload['condicao_pagamento']['parcelas'][0];
                    $this->assertSame('Conta Pagar Payload Conta Azul', $parcela['descricao']);
                    $this->assertSame('2026-06-25', $parcela['data_vencimento']);
                    $this->assertSame('Pagamento de conta a pagar', $parcela['nota']);
                    $this->assertSame('conta-financeira-ext-1', $parcela['conta_financeira']);
                    $this->assertEqualsWithDelta(50.0, $parcela['detalhe_valor']['valor_bruto'], 0.001);
                    $this->assertEqualsWithDelta(50.0, $parcela['detalhe_valor']['valor_liquido'], 0.001);
                    $this->assertEqualsWithDelta(0.0, $parcela['detalhe_valor']['desconto'], 0.001);
                    $this->assertEqualsWithDelta(0.0, $parcela['detalhe_valor']['juros'], 0.001);
                    $this->assertEqualsWithDelta(0.0, $parcela['detalhe_valor']['multa'], 0.001);
                    $this->assertEqualsWithDelta(0.0, $parcela['detalhe_valor']['taxa'], 0.001);

                    return true;
                })
            )
            ->andReturn([
                'status' => 201,
                'body' => '{"id":"conta-pagar-ext-1","id_parcela":"parcela-ext-1"}',
                'json' => ['id' => 'conta-pagar-ext-1', 'id_parcela' => 'parcela-ext-1'],
                'headers' => [],
            ]);
        $this->app->instance(ContaAzulClient::class, $client);

        app(ExportacaoContaAzulService::class)->exportarContaPagar($conexao, $conta);

        $this->assertDatabaseHas('conta_azul_mapeamentos', [
            'tipo_entidade' => ContaAzulEntityType::CONTA_PAGAR,
            'id_local' => $conta->id,
            'id_externo' => 'conta-pagar-ext-1',
        ]);
    }

    public function test_exportar_conta_pagar_sem_categoria_mapeada_falha_sem_alterar_conta_local(): void
    {
        $conexao = $this->criarConexaoContaAzul();
        $categoria = $this->criarCategoriaFinanceira('Categoria Sem Mapeamento Conta Azul');
        $fornecedor = $this->criarFornecedor('Fornecedor Sem Categoria Mapeada Conta Azul');
        $conta = $this->criarContaPagarComCategoriaFornecedor($categoria, $fornecedor);

        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldNotReceive('post');
        $this->app->instance(ContaAzulClient::class, $client);

        $this->expectException(ContaAzulException::class);
        $this->expectExceptionMessage('categoria financeira local sem mapeamento externo');

        try {
            app(ExportacaoContaAzulService::class)->exportarContaPagar($conexao, $conta);
        } finally {
            $this->assertDatabaseHas('contas_pagar', [
                'id' => $conta->id,
                'descricao' => 'Conta Pagar Payload Conta Azul',
            ]);
            $this->assertDatabaseHas('auditoria_logs', [
                'modulo' => 'conta_azul',
                'acao' => 'export',
                'status' => 'falha',
                'entity_type' => ContaAzulEntityType::CONTA_PAGAR,
                'entity_id' => (string) $conta->id,
                'source_kind' => 'sync',
            ]);
        }
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

    private function criarContaPagarComCategoriaFornecedor(CategoriaFinanceira $categoria, Fornecedor $fornecedor): ContaPagar
    {
        $conta = ContaPagar::create([
            'fornecedor_id' => $fornecedor->id,
            'descricao' => 'Conta Pagar Payload Conta Azul',
            'data_emissao' => '2026-06-25',
            'data_vencimento' => '2026-06-25',
            'valor_bruto' => '50.00',
            'desconto' => '0.00',
            'juros' => '0.00',
            'multa' => '0.00',
            'status' => ContaStatus::ABERTA->value,
            'categoria_id' => $categoria->id,
        ]);

        $this->contaPagarIds[] = (int) $conta->id;

        return $conta;
    }

    private function criarCategoriaFinanceira(string $nome): CategoriaFinanceira
    {
        $categoria = CategoriaFinanceira::create([
            'nome' => $nome . ' ' . uniqid(),
            'slug' => 'cat-ca-pagar-payload-' . uniqid(),
            'tipo' => 'despesa',
            'ativo' => true,
        ]);

        $this->categoriaIds[] = (int) $categoria->id;

        return $categoria;
    }

    private function criarFornecedor(string $nome): Fornecedor
    {
        $fornecedor = Fornecedor::create([
            'nome' => $nome . ' ' . uniqid(),
            'status' => 1,
        ]);

        $this->fornecedorIds[] = (int) $fornecedor->id;

        return $fornecedor;
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

    private function mapearEntidade(string $tipoEntidade, int $idLocal, string $idExterno): ContaAzulMapeamento
    {
        $mapeamento = ContaAzulMapeamento::create([
            'tipo_entidade' => $tipoEntidade,
            'id_local' => $idLocal,
            'id_externo' => $idExterno,
            'origem_inicial' => 'test',
            'sincronizado_em' => now(),
        ]);
        $this->mapeamentoIds[] = (int) $mapeamento->id;

        return $mapeamento;
    }
}
