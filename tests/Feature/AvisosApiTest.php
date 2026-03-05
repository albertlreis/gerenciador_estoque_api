<?php

namespace Tests\Feature;

use App\Models\Aviso;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvisosApiTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function autenticar(array $permissoes = []): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Avisos',
            'email' => 'avisos.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, $permissoes, now()->addHour());

        return $usuario;
    }

    public function test_lista_retorna_apenas_avisos_ativos_por_padrao(): void
    {
        $this->autenticar(['avisos.view']);

        $ativo = Aviso::create([
            'titulo' => 'Ativo',
            'conteudo' => 'A',
            'status' => 'publicado',
            'publicar_em' => now()->subHour(),
            'expirar_em' => now()->addDay(),
        ]);

        Aviso::create([
            'titulo' => 'Rascunho',
            'conteudo' => 'B',
            'status' => 'rascunho',
        ]);

        Aviso::create([
            'titulo' => 'Futuro',
            'conteudo' => 'C',
            'status' => 'publicado',
            'publicar_em' => now()->addDay(),
        ]);

        Aviso::create([
            'titulo' => 'Expirado',
            'conteudo' => 'D',
            'status' => 'publicado',
            'publicar_em' => now()->subDays(2),
            'expirar_em' => now()->subMinute(),
        ]);

        $response = $this->getJson('/api/v1/avisos');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($ativo->id, $response->json('data.0.id'));
    }

    public function test_publicar_em_e_expirar_em_funcionam_no_filtro_ativo(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 5, 12, 0, 0));
        $this->autenticar(['avisos.view']);

        Aviso::create([
            'titulo' => 'Publica depois',
            'conteudo' => 'Agendado',
            'status' => 'publicado',
            'publicar_em' => now()->addMinutes(30),
        ]);

        Aviso::create([
            'titulo' => 'Expira logo',
            'conteudo' => 'Expira',
            'status' => 'publicado',
            'publicar_em' => now()->subMinutes(30),
            'expirar_em' => now()->addMinute(),
        ]);

        Carbon::setTestNow(now()->addMinutes(2));

        $response = $this->getJson('/api/v1/avisos');
        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_marcar_lido_cria_ou_atualiza_sem_duplicar(): void
    {
        $usuario = $this->autenticar(['avisos.view']);

        $aviso = Aviso::create([
            'titulo' => 'Leitura',
            'conteudo' => 'Teste',
            'status' => 'publicado',
        ]);

        $this->postJson("/api/v1/avisos/{$aviso->id}/ler")->assertOk();
        $this->postJson("/api/v1/avisos/{$aviso->id}/ler")->assertOk();

        $this->assertDatabaseCount('aviso_leituras', 1);
        $this->assertDatabaseHas('aviso_leituras', [
            'aviso_id' => $aviso->id,
            'usuario_id' => $usuario->id,
        ]);
    }

    public function test_post_avisos_bloqueado_sem_permissao_manage(): void
    {
        $this->autenticar(['avisos.view']);

        $response = $this->postJson('/api/v1/avisos', [
            'titulo' => 'Novo aviso',
            'conteudo' => 'Conteudo',
            'status' => 'publicado',
        ]);

        $response->assertForbidden();
    }
}
