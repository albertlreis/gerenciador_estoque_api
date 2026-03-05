<?php

namespace Tests\Unit;

use App\Models\Categoria;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Services\ImportacaoPedidoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportacaoPedidoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mescla_define_categoria_padrao_quando_item_nao_tem_categoria(): void
    {
        $service = app(ImportacaoPedidoService::class);

        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo' => 'REF-SEM-CAT-001',
                'descricao' => 'Produto sem categoria',
                'quantidade' => '1.00',
                'preco_unitario' => '10.00',
            ],
        ]);

        $this->assertCount(1, $itens);
        $this->assertNotNull($itens[0]['id_categoria']);
        $this->assertSame('Importacao PDF - Sem categoria', $itens[0]['categoria']);
        $this->assertDatabaseHas('categorias', [
            'id' => (int) $itens[0]['id_categoria'],
            'nome' => 'Importacao PDF - Sem categoria',
        ]);
    }

    public function test_mescla_encontra_variacao_por_codigo_barras(): void
    {
        $categoria = Categoria::create(['nome' => 'Categoria Teste']);
        $produto = Produto::create([
            'nome' => 'Produto Código de Barras',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'nome' => 'Variação CB',
            'referencia' => 'REF-CB-001',
            'codigo_barras' => '7891234567890',
            'preco' => 15.5,
            'custo' => 8.2,
        ]);

        $service = app(ImportacaoPedidoService::class);
        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo_barras' => '7891234567890',
                'nome' => 'Item por código de barras',
                'quantidade' => '2',
                'preco_unitario' => '10',
            ],
        ]);

        $this->assertCount(1, $itens);
        $this->assertSame($variacao->id, $itens[0]['id_variacao']);
        $this->assertSame($produto->id, $itens[0]['produto_id']);
        $this->assertSame($categoria->id, $itens[0]['id_categoria']);
    }
}
