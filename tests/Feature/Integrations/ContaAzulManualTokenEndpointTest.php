<?php

namespace Tests\Feature\Integrations;

use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ContaAzulManualTokenEndpointTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $usuario = Usuario::create([
            'nome' => 'Usuário Conta Azul',
            'email' => 'conta-azul+' . uniqid() . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
    }

    public function test_registra_token_manual_com_sucesso(): void
    {
        $service = Mockery::mock(ContaAzulConnectionService::class);
        $service->shouldReceive('persistManualTokens')
            ->once()
            ->andReturn(new ContaAzulConexao([
                'id' => 99,
                'status' => 'ativa',
                'ambiente' => 'homologacao',
                'nome_externo' => 'Loja Teste',
                'ultimo_erro' => null,
            ]));

        $this->app->instance(ContaAzulConnectionService::class, $service);

        $response = $this->postJson('/api/v1/integrations/conta-azul/manual-token', [
            'ambiente' => 'homologacao',
            'access_token' => 'manual-access-token-1234567890',
            'refresh_token' => 'manual-refresh-token-1234567890',
            'expires_in' => 3600,
            'nome_externo' => 'Loja Teste',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('conexao.status', 'ativa')
            ->assertJsonPath('conexao.ambiente', 'homologacao');
    }

    public function test_registra_token_manual_em_homologacao_sem_refresh_token(): void
    {
        $service = Mockery::mock(ContaAzulConnectionService::class);
        $service->shouldReceive('persistManualTokens')
            ->once()
            ->with(null, Mockery::on(function (array $payload) {
                return ($payload['ambiente'] ?? null) === 'homologacao'
                    && ($payload['access_token'] ?? null) === 'manual-access-token-1234567890'
                    && array_key_exists('refresh_token', $payload)
                    && $payload['refresh_token'] === null;
            }))
            ->andReturn(new ContaAzulConexao([
                'id' => 100,
                'status' => 'ativa',
                'ambiente' => 'homologacao',
                'nome_externo' => 'Conta Azul DEV',
                'ultimo_erro' => null,
            ]));

        $this->app->instance(ContaAzulConnectionService::class, $service);

        $response = $this->postJson('/api/v1/integrations/conta-azul/manual-token', [
            'ambiente' => 'homologacao',
            'access_token' => 'manual-access-token-1234567890',
            'expires_in' => 900,
            'nome_externo' => 'Conta Azul DEV',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('conexao.status', 'ativa')
            ->assertJsonPath('conexao.nome_externo', 'Conta Azul DEV');
    }

    public function test_valida_payload_do_token_manual(): void
    {
        $response = $this->postJson('/api/v1/integrations/conta-azul/manual-token', [
            'ambiente' => 'sandbox',
            'access_token' => 'curto',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['ambiente', 'access_token']);
    }

    public function test_exige_refresh_token_em_producao(): void
    {
        $response = $this->postJson('/api/v1/integrations/conta-azul/manual-token', [
            'ambiente' => 'producao',
            'access_token' => 'manual-access-token-1234567890',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['refresh_token']);
    }

    public function test_retorna_erro_funcional_quando_healthcheck_falha(): void
    {
        $service = Mockery::mock(ContaAzulConnectionService::class);
        $service->shouldReceive('persistManualTokens')
            ->once()
            ->andThrow(new ContaAzulException(
                'O token manual foi salvo, mas o teste de conexão com a Conta Azul falhou.',
                'healthcheck_failed'
            ));

        $this->app->instance(ContaAzulConnectionService::class, $service);

        $response = $this->postJson('/api/v1/integrations/conta-azul/manual-token', [
            'ambiente' => 'producao',
            'access_token' => 'manual-access-token-1234567890',
            'refresh_token' => 'manual-refresh-token-1234567890',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('reason', 'healthcheck_failed');
    }
}
