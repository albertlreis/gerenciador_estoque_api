<?php

namespace Tests\Feature;

use App\Models\Aviso;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvisosApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_retorna_apenas_avisos_ativos_por_padrao(): void
    {
        $this->autenticarComPermissoes(['avisos.view']);

        $ativo = Aviso::create([
            'titulo' => 'Ativo',
            'conteudo' => 'Publicado e vigente',
            'status' => 'publicado',
            'publicar_em' => now()->subHour(),
            'expirar_em' => now()->addDay(),
        ]);

        Aviso::create([
            'titulo' => 'Rascunho',
            'conteudo' => 'Nao deve aparecer',
            'status' => 'rascunho',
        ]);

        Aviso::create([
            'titulo' => 'Expirado',
            'conteudo' => 'Nao deve aparecer',
            'status' => 'publicado',
            'publicar_em' => now()->subDays(2),
            'expirar_em' => now()->subMinute(),
        ]);

        $response = $this->getJson('/api/v1/avisos');
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($ativo->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_agendamento_publicar_e_expirar_funciona(): void
    {
        $this->autenticarComPermissoes(['avisos.view']);

        Aviso::create([
            'titulo' => 'Futuro',
            'conteudo' => 'Nao publicado ainda',
            'status' => 'publicado',
            'publicar_em' => now()->addHour(),
        ]);

        Aviso::create([
            'titulo' => 'Sem validade',
            'conteudo' => 'Publicado sem expirar',
            'status' => 'publicado',
            'publicar_em' => now()->subHour(),
            'expirar_em' => null,
        ]);

        $response = $this->getJson('/api/v1/avisos');
        $response->assertOk();

        $titulos = collect($response->json('data'))->pluck('titulo')->all();
        $this->assertContains('Sem validade', $titulos);
        $this->assertNotContains('Futuro', $titulos);
    }

    public function test_marcar_como_lido_cria_sem_duplicar(): void
    {
        $usuario = $this->autenticarComPermissoes(['avisos.view']);
        $aviso = Aviso::create([
            'titulo' => 'Leitura',
            'conteudo' => 'Aviso para leitura',
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

    public function test_usuario_sem_manage_nao_pode_gerenciar_avisos(): void
    {
        $this->autenticarComPermissoes(['avisos.view']);

        $this->postJson('/api/v1/avisos', [
            'titulo' => 'Novo aviso',
            'conteudo' => 'Conteudo',
            'status' => 'publicado',
        ])->assertStatus(403);

        $aviso = Aviso::create([
            'titulo' => 'Existente',
            'conteudo' => 'Teste',
            'status' => 'publicado',
        ]);

        $this->patchJson("/api/v1/avisos/{$aviso->id}", [
            'titulo' => 'Alterado',
        ])->assertStatus(403);
    }

    private function autenticarComPermissoes(array $permissoes): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Avisos',
            'email' => 'avisos.' . uniqid() . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put("permissoes_usuario_{$usuario->id}", $permissoes, now()->addHour());

        return $usuario;
    }
}

