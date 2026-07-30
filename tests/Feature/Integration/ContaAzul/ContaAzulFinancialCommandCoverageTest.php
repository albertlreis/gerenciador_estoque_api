<?php

namespace Tests\Feature\Integration\ContaAzul;

use App\Console\Commands\ContaAzul\ContaAzulImportAllCommand;
use App\Console\Commands\ContaAzul\ContaAzulImportCommand;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ContaAzulExportDispatchService;
use App\Integrations\ContaAzul\Services\ImportacaoContaAzulService;
use App\Jobs\ContaAzul\ExportProdutoContaAzulJob;
use App\Services\AuditoriaLogService;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class ContaAzulFinancialCommandCoverageTest extends TestCase
{
    public function test_import_tudo_inclui_financeiro_completo_em_ordem_operacional(): void
    {
        $this->assertSame([
            'contas_financeiras',
            'categorias_financeiras',
            'centros_custo',
            'pessoas',
            'produtos',
            'vendas',
            'financeiro',
            'contas_pagar',
            'parcelas',
            'baixas',
            'saldos_contas_financeiras',
            'formas_pagamento',
            'notas',
        ], ContaAzulImportAllCommand::ENTIDADES_FINANCEIRO_COMPLETO);

        foreach (ContaAzulImportAllCommand::ENTIDADES_FINANCEIRO_COMPLETO as $entidade) {
            $this->assertArrayHasKey($entidade, ContaAzulImportCommand::ENTIDADES_SUPORTADAS);
        }
    }

    public function test_notas_fiscais_usam_chave_acesso_como_identificador_externo(): void
    {
        $service = (new \ReflectionClass(ImportacaoContaAzulService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ImportacaoContaAzulService::class, 'extractExternalId');
        $method->setAccessible(true);

        $this->assertSame(
            '15260354129336000188550010000001611306815230',
            $method->invoke($service, [
                'data_emissao' => '2026-03-05T14:20:28.566Z',
                'numero_nota' => 161,
                'chave_acesso' => '15260354129336000188550010000001611306815230',
                'status' => 'EMITIDA',
            ])
        );
    }

    public function test_dispatch_produto_conta_azul_e_noop_por_regra_de_negocio(): void
    {
        Bus::fake();

        $auditoria = Mockery::mock(AuditoriaLogService::class);
        $auditoria->shouldReceive('registrar')
            ->once()
            ->with(Mockery::on(fn (array $payload) => $payload['entity_type'] === ContaAzulEntityType::PRODUTO
                && $payload['entity_id'] === 123
                && $payload['status'] === 'ignorado'
                && $payload['message'] === 'Exportação de produtos para Conta Azul desativada por regra de negócio.'
            ));

        $this->app->instance(AuditoriaLogService::class, $auditoria);

        $connections = Mockery::mock(ContaAzulConnectionService::class);
        $connections->shouldNotReceive('latestForLoja');

        $dispatch = new ContaAzulExportDispatchService($connections);
        $dispatch->produto(123, 456, null, ['evento' => 'teste']);

        Bus::assertNotDispatched(ExportProdutoContaAzulJob::class);
    }
}
