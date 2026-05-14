<?php

namespace Tests\Feature\Integrations;

use App\Integrations\ContaAzul\Auth\ContaAzulOAuthService;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ContaAzulPermissionsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_usuario_autenticado_sem_permissao_conta_azul_recebe_403(): void
    {
        $this->actingWithAcesso([]);

        $this->getJson('/api/v1/integrations/conta-azul/status')
            ->assertForbidden()
            ->assertJsonPath('message', 'Sem permissao para acessar a integracao Conta Azul.');
    }

    public function test_header_x_permissoes_nao_concede_acesso_conta_azul(): void
    {
        $this->actingWithAcesso([]);

        $this->withHeaders([
            'X-Permissoes' => json_encode(['conta_azul.visualizar']),
        ])->getJson('/api/v1/integrations/conta-azul/status')
            ->assertForbidden();
    }

    public function test_slugs_conta_azul_nao_concedem_acesso_sem_perfil(): void
    {
        $this->actingWithAcesso([], [
            'conta_azul.visualizar',
            'conta_azul.configurar',
            'conta_azul.importar',
            'conta_azul.conciliar',
            'conta_azul.auditar',
        ]);

        $this->getJson('/api/v1/integrations/conta-azul/status')
            ->assertForbidden();

        $this->postJson('/api/v1/integrations/conta-azul/import/pessoas')
            ->assertForbidden();
    }

    /**
     * @dataProvider perfisAutenticacaoProvider
     */
    public function test_perfis_autorizados_acessam_autenticacao(string $perfil): void
    {
        $this->actingWithAcesso([$perfil]);

        $this->getJson('/api/v1/integrations/conta-azul/status?loja_id=999999')
            ->assertOk()
            ->assertJsonPath('conectado', false);

        $this->postJson('/api/v1/integrations/conta-azul/test-connection')
            ->assertStatus(404);

        $this->postJson('/api/v1/integrations/conta-azul/manual-token', [
            'ambiente' => 'sandbox',
            'access_token' => 'curto',
        ])->assertStatus(422);

        $oauth = Mockery::mock(ContaAzulOAuthService::class);
        $oauth->shouldReceive('buildAuthorizationUrl')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn('https://auth.contaazul.test/oauth');
        $this->app->instance(ContaAzulOAuthService::class, $oauth);

        $this->getJson('/api/v1/integrations/conta-azul/oauth/authorize')
            ->assertOk()
            ->assertJsonPath('url', 'https://auth.contaazul.test/oauth');
    }

    public function test_apenas_desenvolvedor_acessa_fluxos_operacionais(): void
    {
        $this->actingWithAcesso(['Desenvolvedor']);
        $importResponse = $this->postJson('/api/v1/integrations/conta-azul/import/pessoas');
        $this->assertNotSame(403, $importResponse->status());

        $conciliarResponse = $this->postJson('/api/v1/integrations/conta-azul/pendencias/pessoa/1/resolver', []);
        $this->assertNotSame(403, $conciliarResponse->status());

        $this->getJson('/api/v1/integrations/conta-azul/sync-logs')
            ->assertOk();
    }

    /**
     * @dataProvider perfisSomenteAutenticacaoProvider
     */
    public function test_admin_e_financeiro_nao_acessam_fluxos_operacionais(string $perfil): void
    {
        $this->actingWithAcesso([$perfil]);

        $this->postJson('/api/v1/integrations/conta-azul/import/pessoas')
            ->assertForbidden();

        $this->postJson('/api/v1/integrations/conta-azul/pendencias/pessoa/1/resolver', [])
            ->assertForbidden();

        $this->getJson('/api/v1/integrations/conta-azul/pendencias/detalhes')
            ->assertForbidden();

        $this->getJson('/api/v1/integrations/conta-azul/sync-logs')
            ->assertForbidden();

        $this->postJson('/api/v1/integrations/conta-azul/pendencias/criar-local-lote', [])
            ->assertForbidden();
    }

    public static function perfisAutenticacaoProvider(): array
    {
        return [
            'desenvolvedor' => ['Desenvolvedor'],
            'administrador' => ['Administrador'],
            'financeiro' => ['Financeiro'],
        ];
    }

    public static function perfisSomenteAutenticacaoProvider(): array
    {
        return [
            'administrador' => ['Administrador'],
            'financeiro' => ['Financeiro'],
        ];
    }

    private function actingWithAcesso(array $perfis, array $permissoes = []): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Conta Azul Permissoes',
            'email' => 'conta-azul-permissoes+' . uniqid() . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        if ($perfis) {
            Cache::put('perfis_usuario_' . $usuario->id, $perfis, now()->addHour());
        } else {
            Cache::forget('perfis_usuario_' . $usuario->id);
        }

        if ($permissoes) {
            Cache::put('permissoes_usuario_' . $usuario->id, $permissoes, now()->addHour());
        } else {
            Cache::forget('permissoes_usuario_' . $usuario->id);
        }

        return $usuario;
    }
}
