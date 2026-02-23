<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoPermissoesTest extends TestCase
{
    private function criarUsuario(array $perfis = [], array $permissoes = []): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'usuario.permissoes.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        Cache::put('permissoes_usuario_' . $usuario->id, $permissoes, now()->addHour());
        Cache::put('perfis_usuario_' . $usuario->id, $perfis, now()->addHour());

        return $usuario;
    }

    private function criarProdutoBase(): array
    {
        $now = now();

        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Categoria Teste',
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fornecedorId = DB::table('fornecedores')->insertGetId([
            'nome' => 'Fornecedor Teste',
            'cnpj' => null,
            'email' => null,
            'telefone' => null,
            'endereco' => null,
            'status' => 1,
            'observacoes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$categoriaId, $fornecedorId, $now];
    }

    private function criarProdutoDb(int $categoriaId, int $fornecedorId, $now): int
    {
        return DB::table('produtos')->insertGetId([
            'nome' => 'Produto Teste',
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
    }

    public function test_vendedor_pode_gerenciar_imagens(): void
    {
        Storage::fake('public');

        $this->criarUsuario(['Vendedor'], []);
        [$categoriaId, $fornecedorId, $now] = $this->criarProdutoBase();
        $produtoId = $this->criarProdutoDb($categoriaId, $fornecedorId, $now);

        $arquivo = UploadedFile::fake()->image('foto.jpg');

        $response = $this->post(
            "/api/v1/produtos/{$produtoId}/imagens",
            ['image' => $arquivo, 'principal' => true]
        );

        $response->assertCreated();
        $imagemId = $response->json('id');

        $this->postJson("/api/v1/produtos/{$produtoId}/imagens/{$imagemId}/definir-principal")
            ->assertOk();

        $this->deleteJson("/api/v1/produtos/{$produtoId}/imagens/{$imagemId}")
            ->assertNoContent();
    }

    public function test_vendedor_recebe_403_em_produto_variacao_outlet(): void
    {
        $this->criarUsuario(['Vendedor'], []);
        [$categoriaId, $fornecedorId, $now] = $this->criarProdutoBase();
        $produtoId = $this->criarProdutoDb($categoriaId, $fornecedorId, $now);

        $this->postJson('/api/v1/produtos', [
            'nome' => 'Produto Novo',
            'id_categoria' => $categoriaId,
            'id_fornecedor' => $fornecedorId,
        ])->assertStatus(403);

        $this->postJson("/api/v1/produtos/{$produtoId}/variacoes", [
            'referencia' => 'REF-403',
            'preco' => 100,
            'custo' => 50,
        ])->assertStatus(403);

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-OUTLET',
            'nome' => 'Variacao Outlet',
            'preco' => 100,
            'custo' => 50,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->postJson("/api/v1/variacoes/{$variacaoId}/outlets", [
            'quantidade' => 1,
            'formas_pagamento' => [
                ['forma_pagamento_id' => null, 'percentual_desconto' => 10, 'max_parcelas' => 1],
            ],
        ])->assertStatus(403);
    }

    public function test_admin_consegue_criar_produto_e_variacao(): void
    {
        $this->criarUsuario(['Administrador'], [
            'produtos.criar',
            'produtos.editar',
            'produtos.excluir',
            'produto_variacoes.criar',
            'produto_variacoes.editar',
            'produto_variacoes.excluir',
        ]);

        [$categoriaId, $fornecedorId] = $this->criarProdutoBase();

        $response = $this->postJson('/api/v1/produtos', [
            'nome' => 'Produto Admin',
            'id_categoria' => $categoriaId,
            'id_fornecedor' => $fornecedorId,
            'ativo' => true,
        ]);

        $response->assertCreated();
        $produtoId = $response->json('id');

        $this->postJson("/api/v1/produtos/{$produtoId}/variacoes", [
            'referencia' => 'REF-ADMIN',
            'preco' => 100,
            'custo' => 50,
        ])->assertCreated();
    }
}
