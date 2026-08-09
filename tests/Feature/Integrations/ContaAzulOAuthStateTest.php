<?php

namespace Tests\Feature\Integrations;

use App\Integrations\ContaAzul\Auth\ContaAzulOAuthService;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Models\Loja;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ContaAzulOAuthStateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_callback_valido_e_de_uso_unico_persiste_na_loja_correta(): void
    {
        [$loja] = $this->actingAsAdministradorComLoja('oauth-a');
        config(['conta_azul.oauth_front_redirect' => 'http://front.test/integracoes/conta-azul']);

        $oauth = Mockery::mock(ContaAzulOAuthService::class);
        $oauth->shouldReceive('buildAuthorizationUrl')->once()->andReturnUsing(
            fn (string $state) => 'https://auth.contaazul.test/authorize?state=' . urlencode($state)
        );
        $oauth->shouldReceive('exchangeCodeForToken')->once()->with('code-ok')->andReturn([
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
        ]);
        $this->app->instance(ContaAzulOAuthService::class, $oauth);

        $conexao = new ContaAzulConexao(['loja_id' => $loja->id]);
        $conexao->id = 77;
        $connections = Mockery::mock(ContaAzulConnectionService::class);
        $connections->shouldReceive('findOrCreateConexao')->once()->with($loja->id)->andReturn($conexao);
        $connections->shouldReceive('persistTokensFromOAuth')->once()->with($conexao, Mockery::type('array'));
        $connections->shouldReceive('healthcheck')->once()->with($conexao)->andReturn(true);
        $this->app->instance(ContaAzulConnectionService::class, $connections);

        $authorize = $this->getJson('/api/v1/integrations/conta-azul/oauth/authorize?loja_id=' . $loja->id)
            ->assertOk()
            ->json('url');
        parse_str((string) parse_url($authorize, PHP_URL_QUERY), $query);
        $state = (string) ($query['state'] ?? '');
        $this->assertNotSame('', $state);

        $this->get('/api/v1/integrations/conta-azul/callback?state=' . urlencode($state) . '&code=code-ok')
            ->assertRedirect('http://front.test/integracoes/conta-azul?ca=ok');

        $this->get('/api/v1/integrations/conta-azul/callback?state=' . urlencode($state) . '&code=code-ok')
            ->assertRedirect('http://front.test/integracoes/conta-azul?ca=erro&reason=state_invalido');
    }

    public function test_nova_tentativa_invalida_callback_anterior_da_mesma_loja(): void
    {
        [$loja] = $this->actingAsAdministradorComLoja('oauth-b');
        config(['conta_azul.oauth_front_redirect' => 'http://front.test/integracoes/conta-azul']);

        $oauth = Mockery::mock(ContaAzulOAuthService::class);
        $oauth->shouldReceive('buildAuthorizationUrl')->twice()->andReturnUsing(
            fn (string $state) => 'https://auth.contaazul.test/authorize?state=' . urlencode($state)
        );
        $oauth->shouldNotReceive('exchangeCodeForToken');
        $this->app->instance(ContaAzulOAuthService::class, $oauth);
        $this->app->instance(ContaAzulConnectionService::class, Mockery::mock(ContaAzulConnectionService::class));

        $first = $this->getJson('/api/v1/integrations/conta-azul/oauth/authorize?loja_id=' . $loja->id)->json('url');
        $this->getJson('/api/v1/integrations/conta-azul/oauth/authorize?loja_id=' . $loja->id)->assertOk();
        parse_str((string) parse_url($first, PHP_URL_QUERY), $query);

        $this->get('/api/v1/integrations/conta-azul/callback?state=' . urlencode((string) $query['state']) . '&code=code-antigo')
            ->assertRedirect('http://front.test/integracoes/conta-azul?ca=erro&reason=state_invalido');
    }

    public function test_estado_invalido_nao_chega_a_troca_de_token(): void
    {
        config(['conta_azul.oauth_front_redirect' => 'http://front.test/integracoes/conta-azul']);
        $oauth = Mockery::mock(ContaAzulOAuthService::class);
        $oauth->shouldNotReceive('exchangeCodeForToken');
        $this->app->instance(ContaAzulOAuthService::class, $oauth);
        $this->app->instance(ContaAzulConnectionService::class, Mockery::mock(ContaAzulConnectionService::class));

        $this->get('/api/v1/integrations/conta-azul/callback?state=adulterado&code=code')
            ->assertRedirect('http://front.test/integracoes/conta-azul?ca=erro&reason=state_invalido');
    }

    /**
     * @return array{Loja,Usuario}
     */
    private function actingAsAdministradorComLoja(string $codigo): array
    {
        $loja = Loja::create(['codigo' => $codigo, 'nome' => 'Loja ' . $codigo]);
        $usuario = Usuario::create([
            'nome' => 'Administrador OAuth',
            'email' => $codigo . '+' . uniqid() . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        $this->grantAcesso($usuario, 'Administrador', 'conta_azul.configurar');
        Cache::put('perfis_usuario_' . $usuario->id, ['Administrador'], now()->addHour());
        Cache::put('permissoes_usuario_' . $usuario->id, ['conta_azul.configurar'], now()->addHour());

        return [$loja, $usuario];
    }

    private function grantAcesso(Usuario $usuario, string $perfilNome, string $permissaoSlug): void
    {
        if (! Schema::hasTable('acesso_perfis')
            || ! Schema::hasTable('acesso_permissoes')
            || ! Schema::hasTable('acesso_usuario_perfil')
            || ! Schema::hasTable('acesso_perfil_permissao')) {
            return;
        }

        DB::table('acesso_perfis')->updateOrInsert(
            ['nome' => $perfilNome],
            ['descricao' => null, 'updated_at' => now()]
        );
        $perfilId = DB::table('acesso_perfis')->where('nome', $perfilNome)->value('id');

        $permissaoValues = [];
        if (Schema::hasColumn('acesso_permissoes', 'nome')) {
            $permissaoValues['nome'] = $permissaoSlug;
        }
        if (Schema::hasColumn('acesso_permissoes', 'descricao')) {
            $permissaoValues['descricao'] = null;
        }
        if (Schema::hasColumn('acesso_permissoes', 'updated_at')) {
            $permissaoValues['updated_at'] = now();
        }

        DB::table('acesso_permissoes')->updateOrInsert(
            ['slug' => $permissaoSlug],
            $permissaoValues
        );
        $permissaoId = DB::table('acesso_permissoes')->where('slug', $permissaoSlug)->value('id');

        $pivotValues = Schema::hasColumn('acesso_usuario_perfil', 'updated_at')
            ? ['updated_at' => now()]
            : [];
        DB::table('acesso_usuario_perfil')->updateOrInsert([
            'id_usuario' => $usuario->id,
            'id_perfil' => $perfilId,
        ], $pivotValues);
        $pivotValues = Schema::hasColumn('acesso_perfil_permissao', 'updated_at')
            ? ['updated_at' => now()]
            : [];
        DB::table('acesso_perfil_permissao')->updateOrInsert([
            'id_perfil' => $perfilId,
            'id_permissao' => $permissaoId,
        ], $pivotValues);
    }
}
