<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoListaViewTest extends TestCase
{
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
                'estoque_total' => 7,
            ]);
    }
}
