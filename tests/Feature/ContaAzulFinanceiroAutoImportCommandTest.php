<?php

namespace Tests\Feature;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Services\ConciliacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Integrations\ContaAzul\Services\ContaAzulFinanceiroLocalOfficializationService;
use App\Integrations\ContaAzul\Services\ImportacaoContaAzulService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ContaAzulFinanceiroAutoImportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_comando_sai_sem_mutacao_quando_automacao_esta_desligada(): void
    {
        config(['conta_azul.auto_finance_import.enabled' => false]);

        $this->mock(ContaAzulConnectionService::class)
            ->shouldNotReceive('operationalForLoja');
        $this->mock(ImportacaoContaAzulService::class)
            ->shouldNotReceive('importarParaStaging');

        $this->assertSame(0, Artisan::call('conta-azul:financeiro-auto-import'));

        $this->assertDatabaseHas('auditoria_logs', [
            'modulo' => 'conta_azul',
            'acao' => 'financeiro_auto_import',
            'status' => 'ignorado',
            'message' => 'Automacao financeira Conta Azul desligada por configuracao.',
        ]);
    }

    public function test_force_dry_run_only_importa_concilia_e_nao_oficializa(): void
    {
        config([
            'conta_azul.auto_finance_import.enabled' => false,
            'conta_azul.auto_finance_import.officialize_enabled' => false,
            'conta_azul.auto_finance_import.pause_exports' => true,
        ]);

        $conexao = ContaAzulConexao::create([
            'status' => 'ativa',
            'ambiente' => 'homologacao',
        ]);

        $connections = $this->mock(ContaAzulConnectionService::class);
        $connections->shouldReceive('operationalForLoja')
            ->once()
            ->with(null)
            ->andReturn($conexao);
        $connections->shouldReceive('healthcheck')
            ->once()
            ->with($conexao)
            ->andReturnTrue();

        $entidadesImportadas = [];
        $importacao = $this->mock(ImportacaoContaAzulService::class);
        $importacao->shouldReceive('importarParaStaging')
            ->times(11)
            ->withArgs(function ($conexaoRecebida, string $entidade, $lojaId) use ($conexao, &$entidadesImportadas): bool {
                $entidadesImportadas[] = $entidade;

                return $conexaoRecebida->is($conexao) && $lojaId === null;
            })
            ->andReturn(['batch_id' => 1, 'lidos' => 0]);

        $this->mock(ConciliacaoContaAzulService::class)
            ->shouldReceive('conciliarTudo')
            ->once()
            ->with(null)
            ->andReturn(['conciliados' => 0, 'pendentes' => 0, 'conflitos' => 0]);

        $officialization = $this->mock(ContaAzulFinanceiroLocalOfficializationService::class);
        $officialization->shouldReceive('dryRun')
            ->once()
            ->with(null)
            ->andReturn(['contas_pagar' => ['previstos' => 0]]);
        $officialization->shouldNotReceive('oficializar');
        $officialization->shouldNotReceive('backfillPessoasFinanceiras');

        $this->assertSame(0, Artisan::call('conta-azul:financeiro-auto-import', [
            '--force' => true,
            '--dry-run-only' => true,
        ]));

        $this->assertDatabaseHas('auditoria_logs', [
            'modulo' => 'conta_azul',
            'acao' => 'financeiro_auto_import',
            'status' => 'concluido',
        ]);
        $this->assertSame([
            ContaAzulEntityType::PESSOA,
            ContaAzulEntityType::CONTA_FINANCEIRA,
            ContaAzulEntityType::CATEGORIA_FINANCEIRA,
            ContaAzulEntityType::CENTRO_CUSTO,
            ContaAzulEntityType::TITULO,
            ContaAzulEntityType::CONTA_PAGAR,
            ContaAzulEntityType::PARCELA,
            ContaAzulEntityType::BAIXA,
            ContaAzulEntityType::SALDO_CONTA_FINANCEIRA,
            ContaAzulEntityType::FORMA_PAGAMENTO,
            ContaAzulEntityType::NOTA,
        ], $entidadesImportadas);
    }
}
