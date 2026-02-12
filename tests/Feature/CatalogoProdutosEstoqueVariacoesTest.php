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

    private function criarVariacaoComEstoque(Produto $produto, Deposito $deposito, string $referencia, int $quantidade): ProdutoVariacao
    {
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => $referencia,
            'nome' => "Variacao {$referencia}",
            'preco' => 100,
            'custo' => 70,
        ]);

        Estoque::updateOrCreate([
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
        ], [
            'quantidade' => $quantidade,
        ]);

        return $variacao;
    }

    public function test_filtro_com_estoque_retorna_produtos_com_ao_menos_uma_variacao_disponivel(): void
    {
        $this->autenticar();

        $categoria = Categoria::create(['nome' => 'Categoria Teste']);
        $deposito = Deposito::create(['nome' => 'Deposito 1']);

        $produtoA = Produto::create([
            'nome' => 'Produto A',
            'descricao' => 'Descricao',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $produtoB = Produto::create([
            'nome' => 'Produto B',
            'descricao' => 'Descricao',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $produtoC = Produto::create([
            'nome' => 'Produto C',
            'descricao' => 'Descricao',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $this->criarVariacaoComEstoque($produtoA, $deposito, 'A-SEM', 0);
        $variacaoACom = $this->criarVariacaoComEstoque($produtoA, $deposito, 'A-COM', 3);
        $this->criarVariacaoComEstoque($produtoB, $deposito, 'B-SEM-1', 0);
        $this->criarVariacaoComEstoque($produtoB, $deposito, 'B-SEM-2', 0);
        $variacaoCCom = $this->criarVariacaoComEstoque($produtoC, $deposito, 'C-COM', 5);

        $response = $this->getJson('/api/v1/produtos?estoque_status=com_estoque&deposito_id=' . $deposito->id);

        $response->assertStatus(200);

        $data = collect($response->json('data'));
        $idsRetornados = $data->pluck('id')->all();

        $this->assertContains($produtoA->id, $idsRetornados);
        $this->assertContains($produtoC->id, $idsRetornados);
        $this->assertNotContains($produtoB->id, $idsRetornados);

        $produtoAData = $data->firstWhere('id', $produtoA->id);
        $variacoesA = collect($produtoAData['variacoes'] ?? []);
        $this->assertCount(2, $variacoesA);
        $this->assertTrue($variacoesA->contains('id', $variacaoACom->id));
        $this->assertSame(1, (int) data_get($produtoAData, 'estoque_resumo.variacoes_com_estoque'));
        $this->assertSame(1, (int) data_get($produtoAData, 'estoque_resumo.variacoes_sem_estoque'));

        $produtoCData = $data->firstWhere('id', $produtoC->id);
        $variacoesC = collect($produtoCData['variacoes'] ?? []);
        $this->assertCount(1, $variacoesC);
        $this->assertTrue($variacoesC->contains('id', $variacaoCCom->id));
    }

    public function test_filtro_sem_estoque_retorna_produtos_com_ao_menos_uma_variacao_zerada(): void
    {
        $this->autenticar();

        $categoria = Categoria::create(['nome' => 'Categoria Teste']);
        $deposito = Deposito::create(['nome' => 'Deposito 1']);

        $produtoA = Produto::create([
            'nome' => 'Produto A',
            'descricao' => 'Descricao',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $produtoB = Produto::create([
            'nome' => 'Produto B',
            'descricao' => 'Descricao',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $produtoC = Produto::create([
            'nome' => 'Produto C',
            'descricao' => 'Descricao',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $this->criarVariacaoComEstoque($produtoA, $deposito, 'A-SEM', 0);
        $this->criarVariacaoComEstoque($produtoA, $deposito, 'A-COM', 3);
        $this->criarVariacaoComEstoque($produtoB, $deposito, 'B-SEM-1', 0);
        $this->criarVariacaoComEstoque($produtoB, $deposito, 'B-SEM-2', 0);
        $this->criarVariacaoComEstoque($produtoC, $deposito, 'C-COM', 5);

        $response = $this->getJson('/api/v1/produtos?estoque_status=sem_estoque&deposito_id=' . $deposito->id);

        $response->assertStatus(200);

        $data = collect($response->json('data'));
        $idsRetornados = $data->pluck('id')->all();

        $this->assertContains($produtoA->id, $idsRetornados);
        $this->assertContains($produtoB->id, $idsRetornados);
        $this->assertNotContains($produtoC->id, $idsRetornados);

        $produtoAData = $data->firstWhere('id', $produtoA->id);
        $this->assertSame(1, (int) data_get($produtoAData, 'estoque_resumo.variacoes_sem_estoque'));
        $this->assertSame(1, (int) data_get($produtoAData, 'estoque_resumo.variacoes_com_estoque'));
    }
}
