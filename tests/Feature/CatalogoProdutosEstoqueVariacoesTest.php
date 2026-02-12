<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogoProdutosEstoqueVariacoesTest extends TestCase
{
    use RefreshDatabase;

    private function autenticar(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'catalogo-estoque@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
    }

    public function test_filtro_com_estoque_retorna_apenas_variacoes_disponiveis(): void
    {
        $this->autenticar();

        $categoria = Categoria::create(['nome' => 'Categoria Teste']);
        $deposito = Deposito::create(['nome' => 'Deposito 1']);

        $produto = Produto::create([
            'nome' => 'Produto A',
            'descricao' => 'Descricao',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacaoSemEstoque = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-SEM',
            'nome' => 'Variacao sem estoque',
            'preco' => 120,
            'custo' => 80,
        ]);

        $variacaoComEstoque = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-COM',
            'nome' => 'Variacao com estoque',
            'preco' => 150,
            'custo' => 90,
        ]);

        Estoque::updateOrCreate([
            'id_variacao' => $variacaoSemEstoque->id,
            'id_deposito' => $deposito->id,
        ], [
            'quantidade' => 0,
        ]);

        Estoque::updateOrCreate([
            'id_variacao' => $variacaoComEstoque->id,
            'id_deposito' => $deposito->id,
        ], [
            'quantidade' => 3,
        ]);

        $response = $this->getJson('/api/v1/produtos?estoque_status=com_estoque&deposito_id=' . $deposito->id);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $variacoes = $response->json('data.0.variacoes');

        $this->assertCount(1, $variacoes);
        $this->assertSame($variacaoComEstoque->id, $variacoes[0]['id']);
        $this->assertEquals(3, (int) $variacoes[0]['estoque_total']);
    }
}
