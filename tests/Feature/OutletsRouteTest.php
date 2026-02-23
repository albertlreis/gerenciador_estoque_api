<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OutletsRouteTest extends TestCase
{
    public function test_get_outlets_da_variacao_retorna_200(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'usuario.outlet@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

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

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-OUTLET',
            'nome' => 'Variação Teste',
            'preco' => 100,
            'custo' => 50,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $motivoId = DB::table('outlet_motivos')->insertGetId([
            'slug' => 'defeito',
            'nome' => 'Defeito',
            'ativo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('produto_variacao_outlets')->insert([
            'produto_variacao_id' => $variacaoId,
            'motivo_id' => $motivoId,
            'quantidade' => 1,
            'quantidade_restante' => 1,
            'usuario_id' => $usuario->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->getJson("/api/v1/variacoes/{$variacaoId}/outlets");

        $response
            ->assertOk()
            ->assertJsonStructure([
                'variacao_id',
                'produto',
                'outlets',
            ]);
    }
}
