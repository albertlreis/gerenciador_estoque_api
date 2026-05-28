<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoListaViewTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuario(): Usuario
    {
        return Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'usuario.lista.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);
    }

    public function test_view_lista_retorna_variacoes_minimas(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);

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
            'nome' => 'Produto Lista',
            'descricao' => null,
            'id_categoria' => $categoriaId,
            'id_fornecedor' => $fornecedorId,
            'codigo_produto' => 'P-LISTA-001',
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

        DB::table('produto_imagens')->insert([
            'id_produto' => $produtoId,
            'url' => 'lista.jpg',
            'principal' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-LISTA',
            'sku_interno' => 'SKU-LISTA-001',
            'chave_variacao' => 'CATEGORIA TESTE|PRODUTO LISTA|COR:AZUL',
            'nome' => 'Variacao Lista',
            'preco' => 100,
            'custo' => 40,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $depositoId = DB::table('depositos')->insertGetId([
            'nome' => 'Deposito Central',
            'endereco' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('estoque')->updateOrInsert(
            ['id_variacao' => $variacaoId, 'id_deposito' => $depositoId],
            ['quantidade' => 7, 'created_at' => $now, 'updated_at' => $now]
        );

        $response = $this->getJson('/api/v1/produtos?view=lista');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $variacaoId,
                'referencia' => 'REF-LISTA',
                'sku_interno' => 'SKU-LISTA-001',
                'estoque_total' => 7,
            ])
            ->assertJsonFragment([
                'id' => $produtoId,
                'codigo_produto' => 'P-LISTA-001',
            ]);
    }

    public function test_view_minima_retorna_dimensoes_com_nomes_oficiais(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);

        $now = now();

        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Categoria Minima',
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fornecedorId = DB::table('fornecedores')->insertGetId([
            'nome' => 'Fornecedor Minima',
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
            'nome' => 'Produto Minima Dimensoes',
            'descricao' => null,
            'id_categoria' => $categoriaId,
            'id_fornecedor' => $fornecedorId,
            'codigo_produto' => 'P-MIN-DIM',
            'altura' => 70,
            'largura' => 110,
            'profundidade' => 90,
            'peso' => null,
            'manual_conservacao' => null,
            'estoque_minimo' => null,
            'ativo' => true,
            'motivo_desativacao' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-MIN-DIM',
            'sku_interno' => 'SKU-MIN-DIM',
            'chave_variacao' => 'CATEGORIA MINIMA|PRODUTO MINIMA DIMENSOES',
            'nome' => 'Variacao Minima Dimensoes',
            'preco' => 100,
            'custo' => 40,
            'codigo_barras' => null,
            'dimensao_1' => 120,
            'dimensao_2' => 44,
            'dimensao_3' => 80,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->getJson('/api/v1/produtos?view=minima&q=Minima%20Dimensoes');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $produtoId)
            ->assertJsonPath('data.0.altura', 70)
            ->assertJsonPath('data.0.largura', 110)
            ->assertJsonPath('data.0.profundidade', 90)
            ->assertJsonPath('data.0.variacoes.0.id', $variacaoId)
            ->assertJsonPath('data.0.variacoes.0.altura', 80)
            ->assertJsonPath('data.0.variacoes.0.largura', 120)
            ->assertJsonPath('data.0.variacoes.0.profundidade', 44)
            ->assertJsonMissing([
                'dimensao_1' => 120,
                'dimensao_2' => 44,
                'dimensao_3' => 80,
            ]);
    }
}
