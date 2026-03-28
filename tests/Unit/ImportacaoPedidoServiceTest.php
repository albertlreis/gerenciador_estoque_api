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
        $this->assertSame('Importacao XML - Sem categoria', $itens[0]['categoria']);
        $this->assertDatabaseHas('categorias', [
            'id' => (int) $itens[0]['id_categoria'],
            'nome' => 'Importacao XML - Sem categoria',
        ]);
    }

    public function test_mescla_encontra_variacao_por_codigo_barras(): void
    {
        $categoria = Categoria::create(['nome' => 'Categoria Teste']);
        $produto = Produto::create([
            'nome' => 'Produto Codigo de Barras',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'nome' => 'Variacao CB',
            'referencia' => 'REF-CB-001',
            'codigo_barras' => '7891234567890',
            'preco' => 15.5,
            'custo' => 8.2,
        ]);

        $service = app(ImportacaoPedidoService::class);
        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo_barras' => '7891234567890',
                'nome' => 'Item por codigo de barras',
                'quantidade' => '2',
                'preco_unitario' => '10',
            ],
        ]);

        $this->assertCount(1, $itens);
        $this->assertSame($variacao->id, $itens[0]['id_variacao']);
        $this->assertSame($produto->id, $itens[0]['produto_id']);
        $this->assertSame($categoria->id, $itens[0]['id_categoria']);
    }

    public function test_mescla_referencia_unica_vincula_automaticamente_e_mantem_lista_de_preview(): void
    {
        $categoria = Categoria::create(['nome' => 'Categoria Unica']);
        $produto = Produto::create([
            'nome' => 'Produto Unico',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-UNICA',
            'nome' => 'Variacao Unica',
            'preco' => 10,
            'custo' => 5,
        ]);

        $service = app(ImportacaoPedidoService::class);
        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo' => 'REF-UNICA',
                'descricao' => 'Produto XML',
                'quantidade' => '1',
                'preco_unitario' => '99',
            ],
        ]);

        $this->assertSame($variacao->id, $itens[0]['id_variacao']);
        $this->assertCount(1, $itens[0]['variacoes_encontradas']);
    }

    public function test_mescla_referencia_ambigua_exige_selecao_manual_no_preview(): void
    {
        $categoria = Categoria::create(['nome' => 'Categoria Ambigua']);
        $produto = Produto::create([
            'nome' => 'Produto Ambiguo',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-AMBIGUA',
            'nome' => 'Variacao A',
            'preco' => 10,
            'custo' => 5,
        ]);
        ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-AMBIGUA',
            'nome' => 'Variacao B',
            'preco' => 12,
            'custo' => 6,
        ]);

        $service = app(ImportacaoPedidoService::class);
        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo' => 'REF-AMBIGUA',
                'descricao' => 'Produto XML ambiguo',
                'quantidade' => '1',
                'preco_unitario' => '99',
            ],
        ]);

        $this->assertNull($itens[0]['id_variacao']);
        $this->assertCount(2, $itens[0]['variacoes_encontradas']);
    }
}
