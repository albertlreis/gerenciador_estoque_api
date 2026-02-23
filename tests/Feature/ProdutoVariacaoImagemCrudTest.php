<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoVariacaoImagemCrudTest extends TestCase
{
    use DatabaseTransactions;

    private function autenticarUsuario(): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Teste Variacao Imagem',
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
            'nome' => 'Categoria Variacao Imagem ' . uniqid(),
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fornecedorId = DB::table('fornecedores')->insertGetId([
            'nome' => 'Fornecedor Variacao Imagem ' . uniqid(),
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
            'nome' => 'Produto Variacao Imagem ' . uniqid(),
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
            'nome' => 'Variacao Imagem',
            'preco' => 100,
            'custo' => 50,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function pathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = ltrim((string) $path, '/');

        if (str_starts_with($path, 'storage/')) {
            return substr($path, strlen('storage/'));
        }

        return $path;
    }

    public function test_get_sem_imagem_retorna_404(): void
    {
        Storage::fake('public');
        $this->autenticarUsuario();
        $variacaoId = $this->criarVariacao();

        $this->getJson("/api/v1/variacoes/{$variacaoId}/imagem")
            ->assertNotFound();
    }

    public function test_post_upload_cria_imagem(): void
    {
        Storage::fake('public');
        $this->autenticarUsuario();
        $variacaoId = $this->criarVariacao();

        $arquivo = UploadedFile::fake()->image('foto.jpg', 600, 400);

        $response = $this->post("/api/v1/variacoes/{$variacaoId}/imagem", [
            'imagem' => $arquivo,
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['id', 'id_variacao', 'url', 'created_at', 'updated_at'])
            ->assertJsonPath('id_variacao', $variacaoId);

        $url = (string) $response->json('url');
        $path = $this->pathFromUrl($url);

        $this->assertStringContainsString("/storage/produto-variacoes/{$variacaoId}/imagem/", $url);
        Storage::disk('public')->assertExists($path);

        $this->assertDatabaseHas('produto_variacao_imagens', [
            'id_variacao' => $variacaoId,
            'url' => $url,
        ]);
    }

    public function test_post_novamente_substitui_imagem_e_remove_arquivo_antigo(): void
    {
        Storage::fake('public');
        $this->autenticarUsuario();
        $variacaoId = $this->criarVariacao();

        $respInicial = $this->post("/api/v1/variacoes/{$variacaoId}/imagem", [
            'imagem' => UploadedFile::fake()->image('primeira.jpg', 600, 400),
        ])->assertCreated();

        $urlAntiga = (string) $respInicial->json('url');
        $pathAntigo = $this->pathFromUrl($urlAntiga);

        Storage::disk('public')->assertExists($pathAntigo);

        $respNova = $this->post("/api/v1/variacoes/{$variacaoId}/imagem", [
            'imagem' => UploadedFile::fake()->image('segunda.jpg', 640, 480),
        ])->assertOk();

        $urlNova = (string) $respNova->json('url');
        $pathNovo = $this->pathFromUrl($urlNova);

        Storage::disk('public')->assertExists($pathNovo);
        Storage::disk('public')->assertMissing($pathAntigo);

        $count = DB::table('produto_variacao_imagens')
            ->where('id_variacao', $variacaoId)
            ->count();

        $this->assertSame(1, $count);

        $this->assertDatabaseHas('produto_variacao_imagens', [
            'id_variacao' => $variacaoId,
            'url' => $urlNova,
        ]);
    }

    public function test_put_funciona_como_upsert_por_upload(): void
    {
        Storage::fake('public');
        $this->autenticarUsuario();
        $variacaoId = $this->criarVariacao();

        $respInicial = $this->put("/api/v1/variacoes/{$variacaoId}/imagem", [
            'imagem' => UploadedFile::fake()->image('put-inicial.png', 300, 300),
        ]);

        $respInicial->assertOk();

        $urlInicial = (string) $respInicial->json('url');
        $pathInicial = $this->pathFromUrl($urlInicial);
        Storage::disk('public')->assertExists($pathInicial);

        $respAtualizada = $this->put("/api/v1/variacoes/{$variacaoId}/imagem", [
            'imagem' => UploadedFile::fake()->image('put-nova.webp', 300, 300),
        ]);

        $respAtualizada->assertOk();

        $urlAtualizada = (string) $respAtualizada->json('url');
        $pathAtualizada = $this->pathFromUrl($urlAtualizada);

        Storage::disk('public')->assertExists($pathAtualizada);
        Storage::disk('public')->assertMissing($pathInicial);

        $count = DB::table('produto_variacao_imagens')
            ->where('id_variacao', $variacaoId)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_delete_remove_registro_e_arquivo(): void
    {
        Storage::fake('public');
        $this->autenticarUsuario();
        $variacaoId = $this->criarVariacao();

        $post = $this->post("/api/v1/variacoes/{$variacaoId}/imagem", [
            'imagem' => UploadedFile::fake()->image('delete.jpg', 800, 600),
        ])->assertCreated();

        $url = (string) $post->json('url');
        $path = $this->pathFromUrl($url);

        Storage::disk('public')->assertExists($path);

        $this->deleteJson("/api/v1/variacoes/{$variacaoId}/imagem")
            ->assertNoContent();

        $this->assertDatabaseMissing('produto_variacao_imagens', [
            'id_variacao' => $variacaoId,
        ]);

        Storage::disk('public')->assertMissing($path);

        $this->deleteJson("/api/v1/variacoes/{$variacaoId}/imagem")
            ->assertNotFound();
    }
}
