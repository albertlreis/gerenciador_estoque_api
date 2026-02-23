<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoResourceUrlsTest extends TestCase
{
    private function criarUsuario(): Usuario
    {
        return Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'usuario.produto.urls.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);
    }

    private function criarProdutoBase(string $manual): int
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

        return DB::table('produtos')->insertGetId([
            'nome' => 'Produto Teste',
            'descricao' => null,
            'id_categoria' => $categoriaId,
            'id_fornecedor' => $fornecedorId,
            'altura' => null,
            'largura' => null,
            'profundidade' => null,
            'peso' => null,
            'manual_conservacao' => $manual,
            'estoque_minimo' => null,
            'ativo' => true,
            'motivo_desativacao' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function criarImagemPrincipal(int $produtoId, string $url): void
    {
        $now = now();
        DB::table('produto_imagens')->insert([
            'id_produto' => $produtoId,
            'url' => $url,
            'principal' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_show_produto_normaliza_imagem_principal_e_manual_legacy(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);

        $produtoId = $this->criarProdutoBase('manual.pdf');
        $this->criarImagemPrincipal($produtoId, 'foto.jpg');

        $baseUrl = rtrim((string) config('app.url'), '/');

        $response = $this->getJson("/api/v1/produtos/{$produtoId}");

        $response
            ->assertOk()
            ->assertJsonFragment([
                'imagem_principal' => $baseUrl . '/storage/produtos/foto.jpg',
                'manual_conservacao' => '/uploads/manuais/manual.pdf',
            ]);
    }

    public function test_show_produto_normaliza_manual_com_prefixo_manuais(): void
    {
        $usuario = $this->criarUsuario();
        Sanctum::actingAs($usuario);

        $produtoId = $this->criarProdutoBase('manuais/manual.pdf');
        $this->criarImagemPrincipal($produtoId, 'foto2.jpg');

        $baseUrl = rtrim((string) config('app.url'), '/');

        $response = $this->getJson("/api/v1/produtos/{$produtoId}");

        $response
            ->assertOk()
            ->assertJsonFragment([
                'manual_conservacao' => $baseUrl . '/storage/manuais/manual.pdf',
            ]);
    }
}
