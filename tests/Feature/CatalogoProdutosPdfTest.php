<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
use App\Models\Usuario;
use App\Services\CatalogoProdutosPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogoProdutosPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_exporta_todos_os_resultados_filtrados_em_pdf(): void
    {
        $usuario = $this->autenticarComCatalogo();
        $categoria = Categoria::create(['nome' => 'Cadeiras']);
        $outraCategoria = Categoria::create(['nome' => 'Mesas']);
        $this->criarProduto('Cadeira PDF', 'REF-PDF', $categoria, 1250, 3);
        $this->criarProduto('Mesa fora do filtro', 'REF-MESA', $outraCategoria, 900, 2);

        $response = $this->postJson('/api/v1/produtos/catalogo/export', [
            'mode' => 'filtered',
            'filters' => [
                'q' => 'cadeira',
                'id_categoria' => [$categoria->id],
                'estoque_status' => 'com_estoque',
            ],
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertNotNull($usuario->id);
    }

    public function test_exige_selecao_no_modo_selected(): void
    {
        $this->autenticarComCatalogo();

        $this->postJson('/api/v1/produtos/catalogo/export', [
            'mode' => 'selected',
            'variation_ids' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('variation_ids');
    }

    public function test_rejeita_filtros_desconhecidos(): void
    {
        $this->autenticarComCatalogo();

        $this->postJson('/api/v1/produtos/catalogo/export', [
            'mode' => 'filtered',
            'filters' => ['filtro_inexistente' => true],
        ])->assertStatus(422)->assertJsonValidationErrors('filters');
    }

    public function test_bloqueia_usuario_sem_permissao_de_catalogo(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Sem Catalogo',
            'email' => 'sem-catalogo@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, []);

        $this->postJson('/api/v1/produtos/catalogo/export', [
            'mode' => 'filtered',
            'filters' => [],
        ])->assertForbidden();
    }

    public function test_agrupa_mesma_referencia_e_nao_expoe_dados_internos(): void
    {
        $categoria = Categoria::create(['nome' => 'Poltronas']);
        $primeiro = $this->criarProduto('Poltrona Comercial', ' REF 77 ', $categoria, 100, 2);
        $segundo = $this->criarProduto('Cadastro duplicado', 'REF77', $categoria, 150, 0);
        ProdutoVariacaoAtributo::create([
            'id_variacao' => $primeiro->id,
            'atributo' => 'cor',
            'valor' => 'Azul',
        ]);

        $produtos = Produto::query()
            ->whereIn('id', [$primeiro->produto_id, $segundo->produto_id])
            ->with([
                'categoria',
                'imagemPrincipal',
                'variacoes.atributos',
                'variacoes.imagem',
                'variacoes.estoques',
                'variacoes.outlets.formasPagamento.formaPagamento',
            ])->get();

        $cards = app(CatalogoProdutosPdfService::class)->build($produtos);

        $this->assertCount(1, $cards);
        $this->assertSame('A partir de R$ 100,00', $cards[0]['preco_label']);
        $this->assertSame('Disponível', $cards[0]['disponivel'] ? 'Disponível' : 'Indisponível');
        $this->assertContains('Cor: Azul', $cards[0]['atributos']);
        $this->assertArrayNotHasKey('custo', $cards[0]);
        $this->assertArrayNotHasKey('depositos', $cards[0]);
        $this->assertArrayNotHasKey('quantidade', $cards[0]);

        $selecionados = app(CatalogoProdutosPdfService::class)->build($produtos, [$primeiro->id]);
        $this->assertCount(1, $selecionados);
        $this->assertSame('R$ 100,00', $selecionados[0]['preco_label']);
    }

    public function test_mantem_grupos_sem_preco_como_sob_consulta(): void
    {
        $categoria = Categoria::create(['nome' => 'Decoracao']);
        $primeiroSemPreco = $this->criarProduto('Tapete A', 'REF-ZERO-A', $categoria, 0, 1);
        $segundoSemPreco = $this->criarProduto('Tapete B', 'REF-ZERO-B', $categoria, 0, 1);
        $comPreco = $this->criarProduto('Adorno', 'REF-COM-PRECO', $categoria, 1006.95, 1);

        $produtos = Produto::query()->with([
            'categoria',
            'imagemPrincipal',
            'imagens',
            'variacoes.atributos',
            'variacoes.imagem',
            'variacoes.imagens',
            'variacoes.estoques',
            'variacoes.outlets.formasPagamento.formaPagamento',
        ])->get();

        $cards = app(CatalogoProdutosPdfService::class)->build($produtos, [
            $primeiroSemPreco->id,
            $segundoSemPreco->id,
            $comPreco->id,
        ]);

        $this->assertCount(3, $cards);
        $this->assertCount(2, collect($cards)->where('preco_label', 'Preço sob consulta'));
        $this->assertCount(2, collect($cards)->where('preco_sob_consulta', true));
        $this->assertSame(
            'R$ 1.006,95',
            collect($cards)->firstWhere('referencia', 'REF-COM-PRECO')['preco_label']
        );
        $this->assertFalse(
            collect($cards)->firstWhere('referencia', 'REF-COM-PRECO')['preco_sob_consulta']
        );
    }

    private function autenticarComCatalogo(): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Catalogo',
            'email' => 'catalogo-' . uniqid() . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['produtos.catalogo']);

        return $usuario;
    }

    private function criarProduto(
        string $nome,
        string $referencia,
        Categoria $categoria,
        float $preco,
        int $quantidade,
    ): ProdutoVariacao {
        $produto = Produto::create([
            'nome' => $nome,
            'descricao' => 'Descricao comercial',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => $referencia,
            'nome' => 'Variacao',
            'preco' => $preco,
            'custo' => 25,
        ]);
        $deposito = Deposito::create(['nome' => 'Deposito ' . uniqid()]);
        Estoque::updateOrCreate([
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
        ], [
            'quantidade' => $quantidade,
        ]);

        return $variacao;
    }
}
