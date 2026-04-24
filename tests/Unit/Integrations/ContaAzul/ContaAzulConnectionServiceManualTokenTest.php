<?php

namespace Tests\Unit\Integrations\ContaAzul;

use App\Integrations\ContaAzul\Auth\ContaAzulOAuthService;
use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulToken;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ContaAzulConnectionServiceManualTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_persist_manual_tokens_salva_com_criptografia_e_valida_conexao(): void
    {
        $oauth = Mockery::mock(ContaAzulOAuthService::class);
        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldReceive('get')
            ->once()
            ->andReturn([
                'status' => 200,
                'json' => ['items' => []],
            ]);

        $service = new ContaAzulConnectionService(config('conta_azul'), $oauth, $client);

        $conexao = $service->persistManualTokens(null, [
            'ambiente' => 'homologacao',
            'access_token' => 'manual-access-token-1234567890',
            'refresh_token' => 'manual-refresh-token-1234567890',
            'expires_in' => 3600,
            'nome_externo' => 'Conta Azul HML',
            'observacoes' => 'Fluxo assistido',
        ]);

        $conexao->load('token');

        $this->assertSame('ativa', $conexao->status);
        $this->assertSame('homologacao', $conexao->ambiente);
        $this->assertSame('Conta Azul HML', $conexao->nome_externo);
        $this->assertSame('manual-access-token-1234567890', $conexao->token->access_token);
        $this->assertSame('manual-refresh-token-1234567890', $conexao->token->refresh_token);

        $raw = DB::table('conta_azul_tokens')->where('conexao_id', $conexao->id)->first();
        $this->assertNotNull($raw);
        $this->assertNotSame('manual-access-token-1234567890', $raw->access_token);
        $this->assertNotSame('manual-refresh-token-1234567890', $raw->refresh_token);
    }

    public function test_persist_manual_tokens_reaproveita_conexao_existente_e_atualiza_tokens(): void
    {
        $oauth = Mockery::mock(ContaAzulOAuthService::class);
        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldReceive('get')
            ->once()
            ->andReturn([
                'status' => 200,
                'json' => ['items' => []],
            ]);

        $service = new ContaAzulConnectionService(config('conta_azul'), $oauth, $client);

        $conexao = ContaAzulConexao::create([
            'status' => 'erro',
            'ambiente' => 'producao',
            'nome_externo' => 'Conexão antiga',
        ]);

        ContaAzulToken::create([
            'conexao_id' => $conexao->id,
            'access_token' => 'token-antigo',
            'refresh_token' => 'refresh-antigo',
            'expires_at' => CarbonImmutable::now()->addHour(),
        ]);

        $atualizada = $service->persistManualTokens(null, [
            'ambiente' => 'producao',
            'access_token' => 'token-novo-1234567890',
            'refresh_token' => 'refresh-novo-1234567890',
            'expires_in' => 7200,
            'nome_externo' => 'Conexão atualizada',
        ]);

        $atualizada->load('token');

        $this->assertSame($conexao->id, $atualizada->id);
        $this->assertSame('Conexão atualizada', $atualizada->nome_externo);
        $this->assertSame('token-novo-1234567890', $atualizada->token->access_token);
    }

    public function test_persist_manual_tokens_permite_homologacao_sem_refresh_token(): void
    {
        $oauth = Mockery::mock(ContaAzulOAuthService::class);
        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldReceive('get')
            ->once()
            ->andReturn([
                'status' => 200,
                'json' => ['items' => []],
            ]);

        $service = new ContaAzulConnectionService(config('conta_azul'), $oauth, $client);

        $conexao = $service->persistManualTokens(999, [
            'loja_id' => 999,
            'ambiente' => 'homologacao',
            'access_token' => 'manual-access-token-1234567890',
            'expires_in' => 900,
            'nome_externo' => 'Conta Azul DEV',
        ]);

        $conexao->load('token');

        $this->assertSame('ativa', $conexao->status);
        $this->assertSame('homologacao', $conexao->ambiente);
        $this->assertSame('Conta Azul DEV', $conexao->nome_externo);
        $this->assertSame('manual-access-token-1234567890', $conexao->token->access_token);
        $this->assertNull($conexao->token->refresh_token);
    }

    public function test_persist_manual_tokens_marca_erro_quando_healthcheck_falha(): void
    {
        $oauth = Mockery::mock(ContaAzulOAuthService::class);
        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldReceive('get')
            ->once()
            ->andReturn([
                'status' => 401,
                'json' => null,
            ]);

        $service = new ContaAzulConnectionService(config('conta_azul'), $oauth, $client);

        try {
            $service->persistManualTokens(null, [
                'ambiente' => 'homologacao',
                'access_token' => 'manual-access-token-1234567890',
                'refresh_token' => 'manual-refresh-token-1234567890',
                'expires_in' => 3600,
            ]);

            $this->fail('A exceção de healthcheck era esperada.');
        } catch (ContaAzulException $e) {
            $this->assertSame('healthcheck_failed', $e->reason);
        }

        $conexao = ContaAzulConexao::query()->latest('id')->first();
        $this->assertNotNull($conexao);
        $this->assertSame('erro', $conexao->status);
        $this->assertSame('HTTP 401', $conexao->ultimo_erro);
    }

    public function test_get_valid_access_token_em_homologacao_sem_refresh_pede_novo_token_manual(): void
    {
        $oauth = Mockery::mock(ContaAzulOAuthService::class);
        $client = Mockery::mock(ContaAzulClient::class);
        $service = new ContaAzulConnectionService(config('conta_azul'), $oauth, $client);

        $conexao = ContaAzulConexao::create([
            'status' => 'ativa',
            'ambiente' => 'homologacao',
            'nome_externo' => 'Conta Azul DEV',
        ]);

        ContaAzulToken::create([
            'conexao_id' => $conexao->id,
            'access_token' => 'token-expirado',
            'refresh_token' => null,
            'expires_at' => CarbonImmutable::now()->subMinute(),
        ]);

        try {
            $service->getValidAccessToken($conexao->fresh('token'));
            $this->fail('A exceção de refresh token ausente era esperada.');
        } catch (ContaAzulException $e) {
            $this->assertSame('refresh_token_ausente', $e->reason);
            $this->assertStringContainsString('gere um novo access token manual', $e->getMessage());
        }

        $conexao->refresh();
        $this->assertSame('erro', $conexao->status);
        $this->assertSame('Refresh token ausente.', $conexao->ultimo_erro);
    }
}
