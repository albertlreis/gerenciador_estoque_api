<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\OutletMotivo;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoOutlet;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutosOutletTest extends TestCase
{
    use RefreshDatabase;

    private function seedUsuario(): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'teste@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        return $usuario;
    }

    private function criarProdutoOutlet(string $nome, string $referencia, Categoria $categoria): Produto
    {
        $produto = Produto::create([
            'nome' => $nome,
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => $referencia,
            'nome' => 'Var',
            'preco' => 100,
            'custo' => 50,
        ]);

        $motivo = OutletMotivo::create([
            'slug' => 'tempo_estoque',
            'nome' => 'Tempo em estoque',
            'ativo' => true,
        ]);

        ProdutoVariacaoOutlet::create([
            'produto_variacao_id' => $variacao->id,
            'motivo_id' => $motivo->id,
            'quantidade' => 5,
            'quantidade_restante' => 2,
            'usuario_id' => null,
        ]);

        return $produto;
    }

    public function test_lista_produtos_outlet_filtrando_por_categoria_e_referencia(): void
    {
        $this->seedUsuario();

        $categoriaA = Categoria::create(['nome' => 'Cat A']);
        $categoriaB = Categoria::create(['nome' => 'Cat B']);

        $produtoOutlet = $this->criarProdutoOutlet('Produto Outlet', 'REF-OUT', $categoriaA);

        $produtoNormal = Produto::create([
            'nome' => 'Produto Normal',
            'descricao' => 'Desc',
            'id_categoria' => $categoriaB->id,
            'ativo' => true,
        ]);

        ProdutoVariacao::create([
            'produto_id' => $produtoNormal->id,
            'referencia' => 'REF-NORMAL',
            'nome' => 'Var',
            'preco' => 80,
            'custo' => 30,
        ]);

        $response = $this->getJson('/api/v1/produtos?is_outlet=1&referencia=REF-OUT&id_categoria=' . $categoriaA->id);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $produtoOutlet->id);
    }

    public function test_export_outlet_retorna_apenas_produtos_com_outlet(): void
    {
        $this->seedUsuario();

        $categoria = Categoria::create(['nome' => 'Cat A']);
        $produtoOutlet = $this->criarProdutoOutlet('Produto Outlet', 'REF-OUT', $categoria);

        $produtoNormal = Produto::create([
            'nome' => 'Produto Normal',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        ProdutoVariacao::create([
            'produto_id' => $produtoNormal->id,
            'referencia' => 'REF-NORMAL',
            'nome' => 'Var',
            'preco' => 80,
            'custo' => 30,
        ]);

        $response = $this->postJson('/api/v1/produtos/outlet/export', [
            'ids' => [$produtoOutlet->id, $produtoNormal->id],
        ]);

        $response->assertStatus(200);

        $content = method_exists($response, 'streamedContent')
            ? $response->streamedContent()
            : $response->getContent();

        $this->assertStringContainsString('Produto Outlet', $content);
        $this->assertStringNotContainsString('Produto Normal', $content);
    }
}