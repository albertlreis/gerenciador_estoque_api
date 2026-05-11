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
        $this->assertDatabaseHas('conta_azul_sync_logs', [
            'tipo_entidade' => 'pessoa',
            'id_local' => 10,
            'status' => 'ignorado',
            'direcao' => 'export',
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
        $this->assertDatabaseHas('conta_azul_sync_logs', [
            'tipo_entidade' => 'pessoa',
            'id_local' => 11,
            'status' => 'ignorado',
            'direcao' => 'export',
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

        $this->assertDatabaseHas('conta_azul_sync_logs', [
            'tipo_entidade' => 'pessoa',
            'id_local' => 22,
            'status' => 'enfileirado',
            'direcao' => 'export',
        ]);
    }
}
