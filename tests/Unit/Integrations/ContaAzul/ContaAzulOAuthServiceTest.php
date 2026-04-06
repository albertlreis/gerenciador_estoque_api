<?php

namespace Tests\Unit\Integrations\ContaAzul;

use App\Integrations\ContaAzul\Auth\ContaAzulOAuthService;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;

class ContaAzulOAuthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('log', Mockery::mock()->shouldIgnoreMissing());
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_build_authorization_url_contains_state_and_client_id(): void
    {
        $svc = new ContaAzulOAuthService([
            'auth_url' => 'https://auth.example.com',
            'authorize_path' => '/login',
            'token_path' => '/oauth2/token',
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'redirect_uri' => 'https://app/cb',
            'scope' => 'openid',
            'timeout' => 5,
            'connect_timeout' => 5,
        ]);

        $url = $svc->buildAuthorizationUrl('state-xyz');

        $this->assertStringContainsString('state=state-xyz', $url);
        $this->assertStringContainsString('client_id=cid', $url);
        $this->assertStringStartsWith('https://auth.example.com/login?', $url);
    }

    public function test_build_authorization_url_fails_when_required_config_is_missing(): void
    {
        $svc = new ContaAzulOAuthService([
            'auth_url' => 'https://auth.example.com',
            'scope' => 'openid',
        ]);

        $this->expectException(ContaAzulException::class);
        $this->expectExceptionMessage('Configuração OAuth da Conta Azul incompleta.');

        try {
            $svc->buildAuthorizationUrl('state-xyz');
        } catch (ContaAzulException $e) {
            $this->assertSame('config_invalida', $e->reason);
            $this->assertSame(['CONTA_AZUL_CLIENT_ID', 'CONTA_AZUL_CLIENT_SECRET', 'CONTA_AZUL_REDIRECT_URI'], $e->context['missing'] ?? []);

            throw $e;
        }
    }

    public function test_exchange_code_for_token_returns_payload_on_success(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'access-123',
                'refresh_token' => 'refresh-123',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]);

        $svc = new ContaAzulOAuthService($this->baseConfig(), $client);

        $tokens = $svc->exchangeCodeForToken('code-123');

        $this->assertSame('access-123', $tokens['access_token']);
        $this->assertSame('refresh-123', $tokens['refresh_token']);
    }

    public function test_exchange_code_for_token_maps_invalid_grant(): void
    {
        $mock = new MockHandler([
            new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'Authorization code expired',
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]);

        $svc = new ContaAzulOAuthService($this->baseConfig(), $client);

        try {
            $svc->exchangeCodeForToken('expired-code');
            self::fail('Era esperada ContaAzulException.');
        } catch (ContaAzulException $e) {
            $this->assertSame('invalid_grant', $e->reason);
            $this->assertSame(400, $e->context['status'] ?? null);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function baseConfig(): array
    {
        return [
            'auth_url' => 'https://auth.example.com',
            'authorize_path' => '/login',
            'token_path' => '/oauth2/token',
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'redirect_uri' => 'https://app/cb',
            'scope' => 'openid',
            'timeout' => 5,
            'connect_timeout' => 5,
        ];
    }
}
