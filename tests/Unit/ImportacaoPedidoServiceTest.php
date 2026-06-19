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

    public function test_mescla_mantem_item_sem_categoria_para_selecao_manual(): void
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
        $this->assertNull($itens[0]['id_categoria']);
        $this->assertNull($itens[0]['categoria']);
        $this->assertDatabaseMissing('categorias', [
            'nome' => 'Importacao XML - Sem categoria',
        ]);
    }

    public function test_mescla_aplica_categoria_sugerida_em_item_sem_categoria(): void
    {
        $categoria = Categoria::create(['nome' => 'Tapete']);
        $service = app(ImportacaoPedidoService::class);

        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo' => 'REF-TAPETE-001',
                'descricao' => 'Tapete Avanti',
                'quantidade' => '1.00',
                'preco_unitario' => '10.00',
            ],
        ], null, [
            'categoria_sugerida' => [
                'id' => $categoria->id,
                'nome' => $categoria->nome,
            ],
        ]);

        $this->assertSame($categoria->id, $itens[0]['id_categoria']);
        $this->assertSame('Tapete', $itens[0]['categoria']);
    }

    public function test_mescla_ignora_categoria_sugerida_quando_ela_nao_foi_resolvida(): void
    {
        $service = app(ImportacaoPedidoService::class);

        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo' => 'REF-TAPETE-SEM-CADASTRO',
                'descricao' => 'Tapete Avanti sem categoria cadastrada',
                'quantidade' => '1.00',
                'preco_unitario' => '10.00',
            ],
        ], null, [
            'categoria_sugerida' => null,
        ]);

        $this->assertNull($itens[0]['id_categoria']);
        $this->assertNull($itens[0]['categoria']);
        $this->assertDatabaseMissing('categorias', [
            'nome' => 'Tapete',
        ]);
    }

    public function test_mescla_nao_sobrescreve_categoria_de_variacao_com_categoria_sugerida(): void
    {
        $categoriaReal = Categoria::create(['nome' => 'Categoria Real']);
        $categoriaSugerida = Categoria::create(['nome' => 'Tapete']);
        $produto = Produto::create([
            'nome' => 'Produto Existente',
            'id_categoria' => $categoriaReal->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-EXISTENTE',
            'nome' => 'Variacao Existente',
            'preco' => 10,
            'custo' => 5,
        ]);

        $service = app(ImportacaoPedidoService::class);
        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo' => 'REF-EXISTENTE',
                'descricao' => 'Produto XML existente',
                'quantidade' => '1',
                'preco_unitario' => '99',
            ],
        ], null, [
            'categoria_sugerida' => [
                'id' => $categoriaSugerida->id,
                'nome' => $categoriaSugerida->nome,
            ],
        ]);

        $this->assertSame($variacao->id, $itens[0]['id_variacao']);
        $this->assertSame($categoriaReal->id, $itens[0]['id_categoria']);
        $this->assertSame('Categoria Real', $itens[0]['categoria']);
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

    public function test_mescla_sku_interno_repetido_vincula_variacao_mais_recente(): void
    {
        $categoria = Categoria::create(['nome' => 'Categoria SKU Duplicado']);

        $produtoAntigo = Produto::create([
            'nome' => 'Produto Antigo',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        ProdutoVariacao::create([
            'produto_id' => $produtoAntigo->id,
            'referencia' => 'REF-SKU-PEDIDO-OLD',
            'sku_interno' => 'SKU-PEDIDO-DUP',
            'nome' => 'Variacao antiga',
            'preco' => 10,
            'custo' => 5,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $produtoRecente = Produto::create([
            'nome' => 'Produto Recente',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacaoRecente = ProdutoVariacao::create([
            'produto_id' => $produtoRecente->id,
            'referencia' => 'REF-SKU-PEDIDO-NEW',
            'sku_interno' => 'SKU-PEDIDO-DUP',
            'nome' => 'Variacao recente',
            'preco' => 12,
            'custo' => 6,
        ]);

        $service = app(ImportacaoPedidoService::class);
        $itens = $service->mesclarItensComVariacoes([[
            'codigo' => 'SKU-PEDIDO-DUP',
            'descricao' => 'Produto XML SKU duplicado',
            'quantidade' => '1',
            'preco_unitario' => '99',
        ]]);

        $this->assertSame($variacaoRecente->id, $itens[0]['id_variacao']);
        $this->assertSame($produtoRecente->id, $itens[0]['produto_id']);
    }
}
