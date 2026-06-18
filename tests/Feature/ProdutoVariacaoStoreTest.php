<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoVariacaoStoreTest extends TestCase
{
    private function criarUsuario(): Usuario
    {
        return Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'usuario.variacao.store.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);
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

        $produtoId = DB::table('produtos')->insertGetId([
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

        return [$produtoId, $now];
    }

    public function test_post_variacao_persiste_atributos(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['produto_variacoes.criar'], now()->addHour());

        [$produtoId] = $this->criarProdutoBase();

        $payload = [
            'referencia' => 'REF-ATTR',
            'preco' => 100,
            'custo' => 40,
            'codigo_barras' => '123',
            'atributos' => [
                ['atributo' => 'Cor', 'valor' => 'Azul'],
                ['atributo' => 'Tamanho', 'valor' => 'M'],
            ],
        ];

        $response = $this->postJson("/api/v1/produtos/{$produtoId}/variacoes", $payload);

        $response
            ->assertCreated()
            ->assertJsonFragment([
                'referencia' => 'REF-ATTR',
            ]);

        $variacaoId = $response->json('data.id') ?? $response->json('id');

        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $variacaoId,
            'atributo' => 'cor',
            'valor' => 'Azul',
        ]);

        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $variacaoId,
            'atributo' => 'tamanho',
            'valor' => 'M',
        ]);
    }

    public function test_post_variacao_permite_sku_interno_repetido(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['produto_variacoes.criar'], now()->addHour());

        [$produtoId, $now] = $this->criarProdutoBase();
        [$outroProdutoId] = $this->criarProdutoBase();

        DB::table('produto_variacoes')->insert([
            'produto_id' => $outroProdutoId,
            'referencia' => 'REF-STORE-SKU-DUP-OLD',
            'sku_interno' => 'SKU-STORE-DUP',
            'nome' => 'Variacao existente',
            'preco' => 80,
            'custo' => 30,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->postJson("/api/v1/produtos/{$produtoId}/variacoes", [
            'referencia' => 'REF-STORE-SKU-DUP-NEW',
            'sku_interno' => 'SKU-STORE-DUP',
            'preco' => 100,
            'custo' => 40,
        ]);

        $response->assertCreated()
            ->assertJsonFragment([
                'sku_interno' => 'SKU-STORE-DUP',
            ]);

        $variacaoId = $response->json('data.id') ?? $response->json('id');

        $this->assertDatabaseHas('produto_variacoes', [
            'id' => $variacaoId,
            'produto_id' => $produtoId,
            'sku_interno' => 'SKU-STORE-DUP',
        ]);
    }
}
