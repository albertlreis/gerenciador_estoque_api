<?php

namespace Tests\Unit\Integrations\ContaAzul;

use App\Integrations\ContaAzul\Auth\ContaAzulOAuthService;
use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Exceptions\ContaAzulHttpException;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulToken;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ContaAzulHealthStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_retorna_nao_configurado_sem_conexao(): void
    {
        $service = $this->service();
        $service->shouldReceive('latestForLoja')->once()->with(null)->andReturn(null);

        $health = $service->healthStatus();

        $this->assertSame('not_configured', $health['state']);
        $this->assertFalse($health['connected']);
        $this->assertArrayNotHasKey('token', $health);
        $this->assertArrayNotHasKey('error', $health);
    }

    public function test_healthcheck_saudavel_e_reutilizado_por_cinco_minutos(): void
    {
        $conexao = $this->connectionWithToken();
        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldReceive('get')->once()->andReturn(['status' => 200, 'json' => []]);
        $service = $this->service(client: $client);
        $service->shouldReceive('latestForLoja')->twice()->with(null)->andReturn($conexao);

        $first = $service->healthStatus();
        $second = $service->healthStatus();

        $this->assertSame('healthy', $first['state']);
        $this->assertTrue($first['connected']);
        $this->assertSame($first, $second);
        $this->assertSame('ativa', $conexao->status);
    }

    public function test_healthcheck_solicita_token_valido_antes_de_consultar_o_provedor(): void
    {
        $conexao = $this->connectionWithToken(expiresAt: now()->subMinute());
        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldReceive('get')
            ->once()
            ->with(Mockery::type('string'), 'access-renovado', Mockery::type('array'))
            ->andReturn(['status' => 200, 'json' => []]);
        $service = $this->service(client: $client);
        $service->shouldReceive('latestForLoja')->once()->andReturn($conexao);
        $service->shouldReceive('getValidAccessToken')
            ->once()
            ->with($conexao)
            ->andReturn('access-renovado');

        $health = $service->healthStatus();

        $this->assertSame('healthy', $health['state']);
    }

    public function test_falha_de_refresh_e_classificada_como_autenticacao_expirada(): void
    {
        $conexao = $this->connectionWithToken(expiresAt: now()->subMinute());
        $service = $this->service();
        $service->shouldReceive('latestForLoja')->once()->andReturn($conexao);
        $service->shouldReceive('getValidAccessToken')->once()->andThrow(
            new ContaAzulException('detalhe interno', 'refresh_token_falhou')
        );

        $health = $service->healthStatus();

        $this->assertSame('authentication_expired', $health['state']);
        $this->assertFalse($health['connected']);
        $this->assertStringNotContainsString('detalhe interno', $health['message']);
    }

    /**
     * @dataProvider providerFalhasHttp
     */
    public function test_classifica_respostas_http_sem_expor_detalhes(int $status, string $expectedState): void
    {
        $conexao = $this->connectionWithToken();
        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldReceive('get')->once()->andReturn([
            'status' => $status,
            'json' => ['message' => 'segredo-do-provedor'],
        ]);
        $service = $this->service(client: $client);
        $service->shouldReceive('latestForLoja')->once()->andReturn($conexao);

        $health = $service->healthStatus();

        $this->assertSame($expectedState, $health['state']);
        $this->assertFalse($health['connected']);
        $this->assertStringNotContainsString('segredo-do-provedor', $health['message']);
    }

    public function test_falha_de_transporte_e_classificada_como_indisponivel(): void
    {
        $conexao = $this->connectionWithToken();
        $client = Mockery::mock(ContaAzulClient::class);
        $client->shouldReceive('get')->once()->andThrow(
            new ContaAzulHttpException('detalhe interno', 0, null, 'http_error')
        );
        $service = $this->service(client: $client);
        $service->shouldReceive('latestForLoja')->once()->andReturn($conexao);

        $health = $service->healthStatus();

        $this->assertSame('unavailable', $health['state']);
        $this->assertStringNotContainsString('detalhe interno', $health['message']);
    }

    public static function providerFalhasHttp(): array
    {
        return [
            'nao autorizado' => [401, 'authentication_expired'],
            'proibido' => [403, 'authentication_expired'],
            'rate limit' => [429, 'unavailable'],
            'erro do provedor' => [500, 'unavailable'],
        ];
    }

    private function service(
        ?ContaAzulOAuthService $oauth = null,
        ?ContaAzulClient $client = null
    ): ContaAzulConnectionService {
        return Mockery::mock(ContaAzulConnectionService::class, [
            config('conta_azul'),
            $oauth ?? Mockery::mock(ContaAzulOAuthService::class),
            $client ?? Mockery::mock(ContaAzulClient::class),
        ])->makePartial();
    }

    private function connectionWithToken(mixed $expiresAt = null): InMemoryContaAzulConexao
    {
        $conexao = new InMemoryContaAzulConexao([
            'status' => 'ativa',
            'ambiente' => 'producao',
        ]);
        $conexao->id = 123;
        $conexao->setRelation('token', new ContaAzulToken([
            'access_token' => 'access-valido',
            'refresh_token' => 'refresh-valido',
            'expires_at' => $expiresAt ?? now()->addHour(),
        ]));

        return $conexao;
    }
}

class InMemoryContaAzulConexao extends ContaAzulConexao
{
    public function fresh($with = [])
    {
        return $this;
    }

    public function update(array $attributes = [], array $options = [])
    {
        $this->fill($attributes);

        return true;
    }
}
