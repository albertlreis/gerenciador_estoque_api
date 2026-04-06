<?php

namespace Tests\Unit\Integrations\ContaAzul;

use App\Http\Controllers\Integrations\ContaAzulOAuthController;
use App\Integrations\ContaAzul\Auth\ContaAzulOAuthService;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;

class ContaAzulOAuthControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_callback_redirects_with_ok_when_token_and_healthcheck_succeed(): void
    {
        $this->bootstrapFacades([
            'cache' => Mockery::mock()->shouldReceive('pull')->once()->with('ca_oauth:state-1')->andReturn(['loja_id' => 7])->getMock(),
            'config' => new Repository(['conta_azul' => ['oauth_front_redirect' => 'http://front.test/integracoes/conta-azul']]),
            'redirect' => $this->redirector(),
            'log' => Mockery::mock()->shouldIgnoreMissing(),
        ]);

        $oauth = Mockery::mock(ContaAzulOAuthService::class);
        $oauth->shouldReceive('exchangeCodeForToken')->once()->with('code-1')->andReturn([
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires_in' => 3600,
        ]);

        $conexao = new ContaAzulConexao(['id' => 10]);

        $connections = Mockery::mock(ContaAzulConnectionService::class);
        $connections->shouldReceive('findOrCreateConexao')->once()->with(7)->andReturn($conexao);
        $connections->shouldReceive('persistTokensFromOAuth')->once()->with($conexao, Mockery::type('array'));
        $connections->shouldReceive('healthcheck')->once()->with($conexao)->andReturn(true);

        $controller = new ContaAzulOAuthController($oauth, $connections);
        $response = $controller->callback(Request::create('/callback', 'GET', [
            'state' => 'state-1',
            'code' => 'code-1',
        ]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('http://front.test/integracoes/conta-azul?ca=ok', $response->getTargetUrl());
    }

    public function test_callback_redirects_with_specific_reason_when_oauth_fails(): void
    {
        $this->bootstrapFacades([
            'cache' => Mockery::mock()->shouldReceive('pull')->once()->with('ca_oauth:state-2')->andReturn(['loja_id' => null])->getMock(),
            'config' => new Repository(['conta_azul' => ['oauth_front_redirect' => 'http://front.test/integracoes/conta-azul']]),
            'redirect' => $this->redirector(),
            'log' => Mockery::mock()->shouldIgnoreMissing(),
        ]);

        $oauth = Mockery::mock(ContaAzulOAuthService::class);
        $oauth->shouldReceive('exchangeCodeForToken')->once()->with('code-2')->andThrow(
            new ContaAzulException('Authorization code expired', 'invalid_grant', ['status' => 400])
        );

        $connections = Mockery::mock(ContaAzulConnectionService::class);

        $controller = new ContaAzulOAuthController($oauth, $connections);
        $response = $controller->callback(Request::create('/callback', 'GET', [
            'state' => 'state-2',
            'code' => 'code-2',
        ]));

        $this->assertSame(
            'http://front.test/integracoes/conta-azul?ca=erro&reason=invalid_grant',
            $response->getTargetUrl()
        );
    }

    /**
     * @param  array<string, mixed>  $bindings
     */
    private function bootstrapFacades(array $bindings): void
    {
        $app = new Container();
        foreach ($bindings as $key => $binding) {
            $app->instance($key, $binding);
        }

        Container::setInstance($app);
        Facade::setFacadeApplication($app);
    }

    private function redirector(): object
    {
        return new class {
            public function away(string $path): RedirectResponse
            {
                return new RedirectResponse($path);
            }
        };
    }
}
