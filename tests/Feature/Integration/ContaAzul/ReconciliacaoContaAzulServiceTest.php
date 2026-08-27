<?php

namespace Tests\Feature\Integration\ContaAzul;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulReconciliationState;
use App\Integrations\ContaAzul\Services\ConciliacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ImportacaoContaAzulService;
use App\Integrations\ContaAzul\Services\ReconciliacaoContaAzulService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ReconciliacaoContaAzulServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcilia_recurso_sem_depender_da_flag_legada(): void
    {
        config()->set('conta_azul.flags.reconciliacao_ativa', false);

        $conexao = ContaAzulConexao::create([
            'status' => 'ativa',
            'ambiente' => 'homologacao',
        ]);

        $importacao = Mockery::mock(ImportacaoContaAzulService::class);
        $importacao->shouldReceive('importarParaStaging')
            ->once()
            ->with($conexao, ContaAzulEntityType::PRODUTO, null)
            ->andReturn(['batch_id' => 1, 'lidos' => 0]);

        $conciliacao = Mockery::mock(ConciliacaoContaAzulService::class);
        $conciliacao->shouldReceive('conciliarProdutos')
            ->once()
            ->with(null)
            ->andReturn(['conciliados' => 0, 'pendentes' => 0, 'conflitos' => 0]);

        $service = new ReconciliacaoContaAzulService($importacao, $conciliacao);
        $service->reconciliarRecurso($conexao, 'produtos');

        $this->assertTrue(
            ContaAzulReconciliationState::query()
                ->whereNull('loja_id')
                ->where('recurso', 'produtos')
                ->where('status', 'ok')
                ->exists()
        );
    }
}
