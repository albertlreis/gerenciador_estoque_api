<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoVariacaoUpdateTest extends TestCase
{
    private function criarUsuario(): Usuario
    {
        return Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'usuario.variacao.' . uniqid() . '@example.test',
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

    public function test_patch_variacoes_bulk_atualiza_em_lote(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);

        [$produtoId, $now] = $this->criarProdutoBase();

        $variacaoId1 = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-OLD-1',
            'nome' => 'Variacao 1',
            'preco' => 100,
            'custo' => 50,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $variacaoId2 = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-OLD-2',
            'nome' => 'Variacao 2',
            'preco' => 200,
            'custo' => 80,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $payload = [
            [
                'id' => $variacaoId1,
                'preco' => 150,
                'custo' => 60,
                'referencia' => 'REF-NEW-1',
                'codigo_barras' => '123',
            ],
            [
                'id' => $variacaoId2,
                'preco' => 250,
                'custo' => 90,
                'referencia' => 'REF-NEW-2',
                'codigo_barras' => '456',
            ],
        ];

        $response = $this->patchJson("/api/v1/produtos/{$produtoId}/variacoes/bulk", $payload);

        $response
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseHas('produto_variacoes', [
            'id' => $variacaoId1,
            'referencia' => 'REF-NEW-1',
            'preco' => 150,
            'custo' => 60,
        ]);

        $this->assertDatabaseHas('produto_variacoes', [
            'id' => $variacaoId2,
            'referencia' => 'REF-NEW-2',
            'preco' => 250,
            'custo' => 90,
        ]);
    }

    public function test_put_variacao_individual_atualiza_campos(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);

        [$produtoId, $now] = $this->criarProdutoBase();

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-OLD',
            'nome' => 'Variacao',
            'preco' => 100,
            'custo' => 40,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $payload = [
            'referencia' => 'REF-NEW',
            'preco' => 120,
            'custo' => 55,
            'codigo_barras' => '789',
        ];

        $response = $this->putJson("/api/v1/produtos/{$produtoId}/variacoes/{$variacaoId}", $payload);

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $variacaoId,
                'referencia' => 'REF-NEW',
            ]);

        $this->assertDatabaseHas('produto_variacoes', [
            'id' => $variacaoId,
            'referencia' => 'REF-NEW',
            'preco' => 120,
            'custo' => 55,
            'codigo_barras' => '789',
        ]);
    }
}
