<?php

namespace Tests\Feature\Integrations;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContaAzulPermissionsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_usuario_autenticado_sem_permissao_conta_azul_recebe_403(): void
    {
        $this->actingWithPermissoes([]);

        $this->getJson('/api/v1/integrations/conta-azul/status')
            ->assertForbidden()
            ->assertJsonPath('message', 'Sem permissao para acessar a integracao Conta Azul.');
    }

    public function test_header_x_permissoes_nao_concede_acesso_conta_azul(): void
    {
        $this->actingWithPermissoes([]);

        $this->withHeaders([
            'X-Permissoes' => json_encode(['conta_azul.visualizar']),
        ])->getJson('/api/v1/integrations/conta-azul/status')
            ->assertForbidden();
    }

    public function test_slug_visualizar_acessa_status(): void
    {
        $this->actingWithPermissoes(['conta_azul.visualizar']);

        $this->getJson('/api/v1/integrations/conta-azul/status?loja_id=999999')
            ->assertOk()
            ->assertJsonPath('conectado', false);
    }

    public function test_slugs_especificos_liberam_apenas_fluxos_correspondentes(): void
    {
        $this->actingWithPermissoes(['conta_azul.configurar']);
        $configResponse = $this->postJson('/api/v1/integrations/conta-azul/test-connection');
        $this->assertNotSame(403, $configResponse->status());

        $this->actingWithPermissoes(['conta_azul.importar']);
        $importResponse = $this->postJson('/api/v1/integrations/conta-azul/import/pessoas');
        $this->assertNotSame(403, $importResponse->status());

        $this->actingWithPermissoes(['conta_azul.conciliar']);
        $conciliarResponse = $this->postJson('/api/v1/integrations/conta-azul/pendencias/pessoa/1/resolver', []);
        $this->assertNotSame(403, $conciliarResponse->status());

        $this->actingWithPermissoes(['conta_azul.auditar']);
        $this->getJson('/api/v1/integrations/conta-azul/sync-logs')
            ->assertOk();
    }

    public function test_slug_errado_nao_acessa_fluxo_de_outra_permissao(): void
    {
        $this->actingWithPermissoes(['conta_azul.visualizar']);

        $this->postJson('/api/v1/integrations/conta-azul/import/pessoas')
            ->assertForbidden();

        $this->postJson('/api/v1/integrations/conta-azul/test-connection')
            ->assertForbidden();

        $this->getJson('/api/v1/integrations/conta-azul/sync-logs')
            ->assertForbidden();
    }

    private function actingWithPermissoes(array $permissoes): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Conta Azul Permissoes',
            'email' => 'conta-azul-permissoes+' . uniqid() . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        if ($permissoes) {
            Cache::put('permissoes_usuario_' . $usuario->id, $permissoes, now()->addHour());
        } else {
            Cache::forget('permissoes_usuario_' . $usuario->id);
        }

        return $usuario;
    }
}
