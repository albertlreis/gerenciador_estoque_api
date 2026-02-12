<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Fornecedor;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoUpdateCatalogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_produto_aceita_decimal_com_virgula_e_nulls(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'teste@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        $categoria = Categoria::create([
            'nome' => 'Categoria Teste',
        ]);

        $fornecedor = Fornecedor::create([
            'nome' => 'Fornecedor Teste',
            'status' => 1,
        ]);

        $produto = Produto::create([
            'nome' => 'Produto Original',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'id_fornecedor' => $fornecedor->id,
            'ativo' => true,
        ]);

        $payload = [
            'nome' => 'Produto Atualizado',
            'descricao' => 'Nova descricao',
            'id_categoria' => $categoria->id,
            'id_fornecedor' => $fornecedor->id,
            'altura' => '1,25',
            'largura' => '2.50',
            'profundidade' => '',
            'peso' => null,
            'estoque_minimo' => '',
            'ativo' => 1,
        ];

        $response = $this->putJson("/api/v1/produtos/{$produto->id}", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Produto atualizado com sucesso.',
                'id' => $produto->id,
            ]);

        $produto->refresh();

        $this->assertSame('Produto Atualizado', $produto->nome);
        $this->assertSame('Nova descricao', $produto->descricao);
        $this->assertSame('1.25', (string) $produto->altura);
        $this->assertSame('2.50', (string) $produto->largura);
        $this->assertNull($produto->profundidade);
        $this->assertNull($produto->peso);
        $this->assertNull($produto->estoque_minimo);
    }
}