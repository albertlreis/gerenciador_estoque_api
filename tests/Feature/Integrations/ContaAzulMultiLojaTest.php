<?php

namespace Tests\Feature\Integrations;

use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Services\ContaAzulConnectionService;
use App\Models\Loja;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContaAzulMultiLojaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Este cenário começa vazio mesmo quando outro teste deixou dados no
        // banco compartilhado de testes após uma execução interrompida.
        DB::table('conta_azul_tokens')->delete();
        DB::table('conta_azul_conexoes')->delete();
        DB::table('lojas')->delete();
    }

    public function test_crud_gera_codigo_uuid_sem_hifens_e_protege_loja_referenciada(): void
    {
        $this->actingAsAdministrador();

        $this->getJson('/api/v1/integrations/conta-azul/lojas')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('legacy.isolado', true);

        $loja = $this->postJson('/api/v1/integrations/conta-azul/lojas', [
            'nome' => 'Loja Centro',
            'ativo' => true,
        ])->assertCreated()
            ->json('data');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $loja['codigo']);

        $segundaLoja = $this->postJson('/api/v1/integrations/conta-azul/lojas', [
            'nome' => 'Duplicada',
        ])->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $segundaLoja['codigo']);
        $this->assertNotSame($loja['codigo'], $segundaLoja['codigo']);

        ContaAzulConexao::create([
            'loja_id' => $loja['id'],
            'status' => 'inativa',
            'ambiente' => 'homologacao',
        ]);

        $this->deleteJson('/api/v1/integrations/conta-azul/lojas/' . $loja['id'])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'registro_em_uso')
            ->assertJsonPath('dependencias.conexoes', 1)
            ->assertJsonMissingPath('token');

        $this->putJson('/api/v1/integrations/conta-azul/lojas/' . $loja['id'], [
            'nome' => 'Loja Centro Renomeada',
            'ativo' => false,
        ])->assertOk()
            ->assertJsonPath('data.ativo', false)
            ->assertJsonPath('data.codigo', $loja['codigo'])
            ->assertJsonPath('data.nome', 'Loja Centro Renomeada');

        $this->getJson('/api/v1/integrations/conta-azul/oauth/authorize?loja_id=' . $loja['id'])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'loja_inativa');
    }

    public function test_duas_lojas_mantem_tokens_independentes_e_bloqueia_roteamento_ambiguo(): void
    {
        $this->actingAsAdministrador();
        $lojaA = Loja::create(['codigo' => 'loja-a', 'nome' => 'Loja A']);
        $lojaB = Loja::create(['codigo' => 'loja-b', 'nome' => 'Loja B']);
        $service = app(ContaAzulConnectionService::class);

        $conexaoA = $service->findOrCreateConexao($lojaA->id);
        $conexaoB = $service->findOrCreateConexao($lojaB->id);
        $service->persistTokensFromOAuth($conexaoA, [
            'access_token' => 'access-token-loja-a',
            'refresh_token' => 'refresh-token-loja-a',
            'expires_in' => 3600,
        ]);
        $service->persistTokensFromOAuth($conexaoB, [
            'access_token' => 'access-token-loja-b',
            'refresh_token' => 'refresh-token-loja-b',
            'expires_in' => 3600,
        ]);

        $this->assertSame($conexaoA->id, $service->operationalForLoja($lojaA->id)->id);
        $this->assertSame($conexaoB->id, $service->operationalForLoja($lojaB->id)->id);
        $this->assertNotSame(
            DB::table('conta_azul_tokens')->where('conexao_id', $conexaoA->id)->value('access_token'),
            DB::table('conta_azul_tokens')->where('conexao_id', $conexaoB->id)->value('access_token')
        );
        $this->assertNotSame(
            'access-token-loja-a',
            DB::table('conta_azul_tokens')->where('conexao_id', $conexaoA->id)->value('access_token')
        );

        try {
            $service->operationalForLoja();
            $this->fail('O roteamento sem loja deveria ter sido bloqueado.');
        } catch (ContaAzulException $exception) {
            $this->assertSame('loja_ambigua', $exception->reason);
        }

        $this->getJson('/api/v1/integrations/conta-azul/status?loja_id=' . $lojaA->id)
            ->assertOk()
            ->assertJsonPath('conexao.loja_id', $lojaA->id)
            ->assertJsonMissingPath('access_token')
            ->assertJsonMissingPath('refresh_token');
    }

    public function test_reconexao_na_mesma_loja_inativa_a_conexao_anterior(): void
    {
        $loja = Loja::create(['codigo' => 'loja-reconexao', 'nome' => 'Loja Reconexão']);
        $service = app(ContaAzulConnectionService::class);

        $primeira = $service->findOrCreateConexao($loja->id);
        $service->persistTokensFromOAuth($primeira, [
            'access_token' => 'access-token-primeiro-' . uniqid(),
            'refresh_token' => 'refresh-token-primeiro-' . uniqid(),
            'expires_in' => 3600,
        ]);

        $segunda = ContaAzulConexao::create([
            'loja_id' => $loja->id,
            'status' => 'inativa',
            'ambiente' => 'producao',
        ]);
        $service->persistTokensFromOAuth($segunda, [
            'access_token' => 'access-token-segundo-' . uniqid(),
            'refresh_token' => 'refresh-token-segundo-' . uniqid(),
            'expires_in' => 3600,
        ]);

        $this->assertDatabaseHas('conta_azul_conexoes', [
            'id' => $primeira->id,
            'status' => 'inativa',
        ]);
        $this->assertDatabaseHas('conta_azul_conexoes', [
            'id' => $segunda->id,
            'status' => 'ativa',
        ]);
        $this->assertSame($segunda->id, $service->operationalForLoja($loja->id)->id);
    }

    public function test_staging_financeiro_isolado_por_loja_com_mesmo_identificador_externo(): void
    {
        $lojaA = Loja::create(['codigo' => 'loja-staging-a', 'nome' => 'Loja Staging A']);
        $lojaB = Loja::create(['codigo' => 'loja-staging-b', 'nome' => 'Loja Staging B']);
        $payload = json_encode(['nome' => 'Conta financeira compartilhada'], JSON_THROW_ON_ERROR);

        foreach ([$lojaA, $lojaB] as $loja) {
            DB::table('stg_conta_azul_contas_financeiras')->insert([
                'loja_id' => $loja->id,
                'identificador_externo' => 'conta-financeira-mesma-id',
                'payload_json' => $payload,
                'hash_payload' => hash('sha256', $payload . $loja->id),
                'status_conciliacao' => 'novo',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(
            2,
            DB::table('stg_conta_azul_contas_financeiras')
                ->where('identificador_externo', 'conta-financeira-mesma-id')
                ->count()
        );
        $this->assertSame(
            1,
            DB::table('stg_conta_azul_contas_financeiras')
                ->where('loja_id', $lojaA->id)
                ->where('identificador_externo', 'conta-financeira-mesma-id')
                ->count()
        );
    }

    public function test_conexao_legada_sem_loja_permanece_isolada(): void
    {
        $lojasAntes = Loja::query()->count();
        $legacy = ContaAzulConexao::create([
            'loja_id' => null,
            'status' => 'ativa',
            'ambiente' => 'producao',
        ]);

        $this->assertNull(app(ContaAzulConnectionService::class)->latestForLoja());
        $this->assertDatabaseHas('conta_azul_conexoes', ['id' => $legacy->id, 'loja_id' => null]);
        $this->assertSame($lojasAntes, Loja::query()->count());
    }

    private function actingAsAdministrador(): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Administrador Conta Azul Multiloja',
            'email' => 'conta-azul-multiloja+' . uniqid() . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('perfis_usuario_' . $usuario->id, ['Administrador'], now()->addHour());
        Cache::put('permissoes_usuario_' . $usuario->id, ['conta_azul.configurar'], now()->addHour());

        return $usuario;
    }
}
