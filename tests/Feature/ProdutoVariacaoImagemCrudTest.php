<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoVariacaoImagemCrudTest extends TestCase
{
    use DatabaseTransactions;

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
        $this->autenticarUsuario();
        $variacaoId = $this->criarVariacao();
        $url = 'https://placehold.co/600x400.jpg';

        $post = $this->postJson("/api/v1/variacoes/{$variacaoId}/imagem", [
            'url' => $url,
        ]);

        $post->assertCreated()
            ->assertJsonStructure(['id', 'id_variacao', 'url', 'created_at', 'updated_at'])
            ->assertJson([
                'id_variacao' => $variacaoId,
                'url' => $url,
            ]);

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
        $this->autenticarUsuario();
        $variacaoId = $this->criarVariacao();

        $this->postJson("/api/v1/variacoes/{$variacaoId}/imagem", [
            'url' => 'https://placehold.co/600x400.jpg',
        ])->assertCreated();

        $urlAtualizada = 'https://placehold.co/600x400.jpg?text=atualizada';

        $this->postJson("/api/v1/variacoes/{$variacaoId}/imagem", [
            'url' => $urlAtualizada,
        ])->assertOk()
            ->assertJson([
                'id_variacao' => $variacaoId,
                'url' => $urlAtualizada,
            ]);

        $count = DB::table('produto_variacao_imagens')
            ->where('id_variacao', $variacaoId)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_put_funciona_como_upsert(): void
    {
        $this->autenticarUsuario();
        $variacaoId = $this->criarVariacao();

        $urlInicial = 'https://placehold.co/600x400.jpg';
        $urlAtualizada = 'https://placehold.co/600x400.jpg?text=put';

        $this->putJson("/api/v1/variacoes/{$variacaoId}/imagem", [
            'url' => $urlInicial,
        ])->assertOk()
            ->assertJson([
                'id_variacao' => $variacaoId,
                'url' => $urlInicial,
            ]);

        $this->putJson("/api/v1/variacoes/{$variacaoId}/imagem", [
            'url' => $urlAtualizada,
        ])->assertOk()
            ->assertJson([
                'id_variacao' => $variacaoId,
                'url' => $urlAtualizada,
            ]);

        $count = DB::table('produto_variacao_imagens')
            ->where('id_variacao', $variacaoId)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_delete_remove_imagem_e_retorna_404_quando_inexistente(): void
    {
        $this->autenticarUsuario();
        $variacaoId = $this->criarVariacao();

        $this->postJson("/api/v1/variacoes/{$variacaoId}/imagem", [
            'url' => 'https://placehold.co/600x400.jpg',
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

