<?php

namespace Tests\Feature\Integrations;

use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulToken;
use App\Integrations\ContaAzul\Services\ContaAzulExportDispatchService;
use App\Jobs\ContaAzul\ExportClienteContaAzulJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContaAzulExportDispatchServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_registra_log_ignorado_quando_nao_ha_conexao(): void
    {
        Queue::fake();
        DB::table('conta_azul_conexoes')->delete();

        $service = app(ContaAzulExportDispatchService::class);
        $service->cliente(10, null, ['evento' => 'teste_sem_conexao']);

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('auditoria_logs', [
            'modulo' => 'conta_azul',
            'acao' => 'export',
            'entity_type' => 'pessoa',
            'entity_id' => '10',
            'status' => 'ignorado',
            'source_kind' => 'sync',
        ]);
    }

    public function test_nao_enfileira_job_quando_conexao_nao_esta_ativa(): void
    {
        Queue::fake();

        $conexao = ContaAzulConexao::create([
            'status' => 'erro',
            'ambiente' => 'homologacao',
        ]);

        ContaAzulToken::create([
            'conexao_id' => $conexao->id,
            'access_token' => 'token-valido',
            'refresh_token' => 'refresh-valido',
            'expires_at' => now()->addHour(),
        ]);

        $service = app(ContaAzulExportDispatchService::class);
        $service->cliente(11, null, ['evento' => 'teste_conexao_inativa']);

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('auditoria_logs', [
            'modulo' => 'conta_azul',
            'acao' => 'export',
            'entity_type' => 'pessoa',
            'entity_id' => '11',
            'status' => 'ignorado',
            'source_kind' => 'sync',
        ]);
    }

    public function test_enfileira_job_e_registra_log_quando_conexao_tem_token(): void
    {
        Queue::fake();

        $conexao = ContaAzulConexao::create([
            'status' => 'ativa',
            'ambiente' => 'homologacao',
        ]);

        ContaAzulToken::create([
            'conexao_id' => $conexao->id,
            'access_token' => 'token-valido',
            'refresh_token' => 'refresh-valido',
            'expires_at' => now()->addHour(),
        ]);

        $service = app(ContaAzulExportDispatchService::class);
        $service->cliente(22, null, ['evento' => 'cliente_criado']);

        Queue::assertPushed(ExportClienteContaAzulJob::class, function (ExportClienteContaAzulJob $job) {
            return $job->clienteId === 22;
        });

        $this->assertDatabaseHas('auditoria_logs', [
            'modulo' => 'conta_azul',
            'acao' => 'export',
            'entity_type' => 'pessoa',
            'entity_id' => '22',
            'status' => 'enfileirado',
            'source_kind' => 'sync',
        ]);
    }
}
