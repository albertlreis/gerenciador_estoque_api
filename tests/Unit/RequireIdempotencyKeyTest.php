<?php

namespace Tests\Unit;

use App\Http\Middleware\RequireIdempotencyKey;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RequireIdempotencyKeyTest extends TestCase
{
    public function test_repeticao_retorna_resposta_original_sem_reexecutar_operacao(): void
    {
        Cache::flush();
        $executions = 0;
        $next = function () use (&$executions) {
            $executions++;
            return response()->json(['execution' => $executions], 200);
        };

        $first = app(RequireIdempotencyKey::class)->handle($this->request(['quantidade' => 1]), $next);
        $second = app(RequireIdempotencyKey::class)->handle($this->request(['quantidade' => 1]), $next);

        $this->assertSame(1, $executions);
        $this->assertSame($first->getContent(), $second->getContent());
    }

    public function test_mesma_chave_com_conteudo_diferente_e_rejeitada(): void
    {
        Cache::flush();
        $middleware = app(RequireIdempotencyKey::class);
        $middleware->handle($this->request(['quantidade' => 1]), fn () => response()->json(['ok' => true]));

        $response = $middleware->handle(
            $this->request(['quantidade' => 2]),
            fn () => response()->json(['should_not_run' => true])
        );

        $this->assertSame(409, $response->getStatusCode());
    }

    private function request(array $payload): Request
    {
        $request = Request::create('/api/v1/consignacoes/10/devolucoes', 'POST', $payload);
        $request->headers->set('Idempotency-Key', 'test-key-12345678');
        $request->setUserResolver(fn () => (object) ['id' => 42]);
        $request->setRouteResolver(fn () => new Route('POST', 'api/v1/consignacoes/{id}/devolucoes', fn () => null));

        return $request;
    }
}
