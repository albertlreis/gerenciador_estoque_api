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

class EstoqueAtualFiltroTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'estoque@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        $categoria = Categoria::create(['nome' => 'Categoria Teste']);
        $produto = Produto::create([
            'nome' => 'Produto Estoque',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacaoCom = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-COM',
            'nome' => 'Variacao Com',
            'preco' => 100,
            'custo' => 50,
        ]);

        $variacaoSem = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-SEM',
            'nome' => 'Variacao Sem',
            'preco' => 120,
            'custo' => 60,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito Teste']);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacaoCom->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 5]
        );

        Estoque::updateOrCreate(
            ['id_variacao' => $variacaoSem->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 0]
        );

        return [$variacaoCom, $variacaoSem];
    }

    public function test_filtra_apenas_com_estoque(): void
    {
        [$variacaoCom, $variacaoSem] = $this->seedBase();

        $response = $this->getJson('/api/v1/estoque/atual?estoque_status=com_estoque');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoCom->id, $ids);
        $this->assertNotContains($variacaoSem->id, $ids);
    }

    public function test_filtra_apenas_sem_estoque(): void
    {
        [$variacaoCom, $variacaoSem] = $this->seedBase();

        $response = $this->getJson('/api/v1/estoque/atual?estoque_status=sem_estoque');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoSem->id, $ids);
        $this->assertNotContains($variacaoCom->id, $ids);
    }

    public function test_compatibilidade_zerados_ainda_funciona(): void
    {
        [$variacaoCom, $variacaoSem] = $this->seedBase();

        $response = $this->getJson('/api/v1/estoque/atual?zerados=1');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoSem->id, $ids);
        $this->assertNotContains($variacaoCom->id, $ids);
    }
}
