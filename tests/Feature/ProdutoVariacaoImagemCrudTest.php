<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoVariacaoImagemCrudTest extends TestCase
{
    use DatabaseTransactions;

    private function fakeImagemPng(string $nome = 'variacao.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+yF9kAAAAASUVORK5CYII='
        ));
    }

    private function autenticarUsuario(): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuário Teste Variação Imagem',
            'email' => 'usuario.variacao.imagem.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, [
            'produtos.editar',
            'produto_variacoes.editar',
        ], now()->addHour());

        return $usuario;
    }

    private function criarVariacao(): int
    {
        $now = now();

        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Categoria Variação Imagem ' . uniqid(),
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fornecedorId = DB::table('fornecedores')->insertGetId([
            'nome' => 'Fornecedor Variação Imagem ' . uniqid(),
            'cnpj' => null,
            'email' => null,
            'telefone' => null,
            'endereco' => null,
            'status' => 1,
            'observacoes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $produtoId = DB::table('produtos')->insertGetId([
            'nome' => 'Produto Variação Imagem ' . uniqid(),
            'descricao' => null,
            'id_categoria' => $categoriaId,
            'id_fornecedor' => $fornecedorId,
            'altura' => null,
            'largura' => null,
            'profundidade' => null,
            'peso' => null,
            'manual_conservacao' => null,
            'estoque_minimo' => null,
            'ativo' => true,
            'motivo_desativacao' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-VAR-IMG-' . uniqid(),
            'nome' => 'Variação Imagem',
            'preco' => 100,
            'custo' => 50,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_get_retorna_404_quando_variacao_nao_existe(): void
    {
        $this->autenticarUsuario();

        $this->getJson('/api/v1/variacoes/999999999/imagem')
            ->assertNotFound();
    }

    public function test_get_sem_imagem_retorna_404(): void
    {
        $this->autenticarUsuario();
        $variacaoId = $this->criarVariacao();

        $this->getJson("/api/v1/variacoes/{$variacaoId}/imagem")
            ->assertNotFound();
    }

    public function test_post_cria_imagem_e_get_retorna_200(): void
    {
        Storage::fake('public');
        $this->autenticarUsuario();
        $variacaoId = $this->criarVariacao();
        $arquivo = $this->fakeImagemPng('variacao.png');

        $post = $this->post("/api/v1/variacoes/{$variacaoId}/imagem", [
            'imagem' => $arquivo,
        ]);

        $post->assertCreated()
            ->assertJsonStructure(['id', 'id_variacao', 'url', 'created_at', 'updated_at'])
            ->assertJsonPath('id_variacao', $variacaoId);

        $url = (string) $post->json('url');
        $this->assertStringContainsString('/storage/produtos/variacoes/', $url);

        $this->assertDatabaseHas('produto_variacao_imagens', [
            'id_variacao' => $variacaoId,
            'url' => $url,
        ]);

        $get = $this->getJson("/api/v1/variacoes/{$variacaoId}/imagem");

        $get->assertOk()
            ->assertJsonStructure(['id', 'id_variacao', 'url', 'created_at', 'updated_at'])
            ->assertJson([
                'id_variacao' => $variacaoId,
                'url' => $url,
            ]);
    }

    public function test_post_substitui_imagem_mantendo_apenas_um_registro_por_variacao(): void
    {
        Storage::fake('public');
        $this->autenticarUsuario();
        $variacaoId = $this->criarVariacao();

        $this->post("/api/v1/variacoes/{$variacaoId}/imagem", [
            'imagem' => $this->fakeImagemPng('inicial.png'),
        ])->assertCreated();

        $update = $this->post("/api/v1/variacoes/{$variacaoId}/imagem", [
            'imagem' => $this->fakeImagemPng('atualizada.png'),
        ]);
        $update->assertOk()->assertJsonPath('id_variacao', $variacaoId);

        $count = DB::table('produto_variacao_imagens')
            ->where('id_variacao', $variacaoId)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_put_funciona_como_upsert(): void
    {
        Storage::fake('public');
        $this->autenticarUsuario();
        $variacaoId = $this->criarVariacao();

        $this->post("/api/v1/variacoes/{$variacaoId}/imagem", [
            '_method' => 'PUT',
            'imagem' => $this->fakeImagemPng('put-1.png'),
        ])->assertOk()->assertJsonPath('id_variacao', $variacaoId);

        $this->post("/api/v1/variacoes/{$variacaoId}/imagem", [
            '_method' => 'PUT',
            'imagem' => $this->fakeImagemPng('put-2.png'),
        ])->assertOk()->assertJsonPath('id_variacao', $variacaoId);

        $count = DB::table('produto_variacao_imagens')
            ->where('id_variacao', $variacaoId)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_delete_remove_imagem_e_retorna_404_quando_inexistente(): void
    {
        Storage::fake('public');
        $this->autenticarUsuario();
        $variacaoId = $this->criarVariacao();

        $this->post("/api/v1/variacoes/{$variacaoId}/imagem", [
            'imagem' => $this->fakeImagemPng('delete.png'),
        ])->assertCreated();

        $this->deleteJson("/api/v1/variacoes/{$variacaoId}/imagem")
            ->assertNoContent();

        $this->assertDatabaseMissing('produto_variacao_imagens', [
            'id_variacao' => $variacaoId,
        ]);

        $this->deleteJson("/api/v1/variacoes/{$variacaoId}/imagem")
            ->assertNotFound();
    }
}
