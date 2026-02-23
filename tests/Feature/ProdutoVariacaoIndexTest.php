<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoVariacaoIndexTest extends TestCase
{
    private function criarUsuario(): Usuario
    {
        return Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'usuario.variacao.index.' . uniqid() . '@example.test',
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

    public function test_index_variacoes_retorna_atributos_outlets_e_estoques(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);

        [$produtoId, $now] = $this->criarProdutoBase();

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => 'REF-INDEX',
            'nome' => 'Variacao Index',
            'preco' => 100,
            'custo' => 40,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('produto_variacao_atributos')->insert([
            'id_variacao' => $variacaoId,
            'atributo' => 'cor',
            'valor' => 'Azul',
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
            ['quantidade' => 5, 'created_at' => $now, 'updated_at' => $now]
        );

        $motivoId = DB::table('outlet_motivos')->where('slug', 'defeito')->value('id');
        if (!$motivoId) {
            $motivoId = DB::table('outlet_motivos')->insertGetId([
                'slug' => 'defeito',
                'nome' => 'Defeito',
                'ativo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $outletId = DB::table('produto_variacao_outlets')->insertGetId([
            'produto_variacao_id' => $variacaoId,
            'motivo_id' => $motivoId,
            'quantidade' => 2,
            'quantidade_restante' => 1,
            'usuario_id' => $usuario->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $formaPagamentoId = DB::table('outlet_formas_pagamento')->where('slug', 'pix')->value('id');
        if (!$formaPagamentoId) {
            $formaPagamentoId = DB::table('outlet_formas_pagamento')->insertGetId([
                'slug' => 'pix',
                'nome' => 'Pix Outlet',
                'max_parcelas_default' => 1,
                'percentual_desconto_default' => 10,
                'ativo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('produto_variacao_outlet_pagamentos')->insert([
            'produto_variacao_outlet_id' => $outletId,
            'forma_pagamento_id' => $formaPagamentoId,
            'percentual_desconto' => 10,
            'max_parcelas' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->getJson("/api/v1/produtos/{$produtoId}/variacoes");

        $response
            ->assertOk()
            ->assertJsonFragment([
                'atributo' => 'cor',
                'valor' => 'Azul',
            ])
            ->assertJsonFragment([
                'quantidade' => 5,
            ])
            ->assertJsonFragment([
                'nome' => 'Defeito',
            ])
            ->assertJsonFragment([
                'nome' => 'Pix Outlet',
            ]);
    }
}
