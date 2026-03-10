<?php

namespace Tests\Unit;

use App\Models\Produto;
use App\Models\ProdutoConjunto;
use App\Services\OutletCatalogoPdfService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OutletCatalogoPdfServiceTest extends TestCase
{
    use DatabaseTransactions;

    private int $categoriaId;
    private int $fornecedorId;
    private int $motivoId;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $this->categoriaId = (int) DB::table('categorias')->insertGetId([
            'nome' => 'Categoria Outlet PDF ' . uniqid(),
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->fornecedorId = (int) DB::table('fornecedores')->insertGetId([
            'nome' => 'Fornecedor Outlet PDF ' . uniqid(),
            'cnpj' => null,
            'email' => null,
            'telefone' => null,
            'endereco' => null,
            'status' => 1,
            'observacoes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->motivoId = (int) DB::table('outlet_motivos')->insertGetId([
            'slug' => 'motivo-outlet-pdf-' . uniqid(),
            'nome' => 'Motivo Outlet PDF',
            'ativo' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_renderiza_conjunto_incompleto_soma_apenas_disponiveis_e_exclui_itens_da_lista_avulsa(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('conjuntos/hero-soma.jpg', 'hero');

        [$produtoA, $variacaoA] = $this->criarProdutoComVariacao('Sofa', 'SOF-001', 100.00, 2);
        [$produtoB, $variacaoB] = $this->criarProdutoComVariacao('Chaise', 'CHA-001', 200.00, 1);
        [$produtoC, $variacaoC] = $this->criarProdutoComVariacao('Painel', 'PAI-001', 300.00, 0);
        [$produtoD, $variacaoD] = $this->criarProdutoComVariacao('Poltrona', 'POL-001', 150.00, 4);

        $this->criarConjunto(
            nome: 'Conjunto Living',
            heroPath: 'conjuntos/hero-soma.jpg',
            precoModo: 'soma',
            principalVariacaoId: null,
            itens: [
                ['produto_variacao_id' => $variacaoA, 'label' => 'Sofa', 'ordem' => 1],
                ['produto_variacao_id' => $variacaoB, 'label' => 'Chaise', 'ordem' => 2],
                ['produto_variacao_id' => $variacaoC, 'label' => 'Painel', 'ordem' => 3],
            ]
        );

        $resultado = $this->service()->build($this->carregarProdutos([$produtoA, $produtoB, $produtoC, $produtoD]));

        $this->assertCount(1, $resultado['conjuntos']);
        $this->assertCount(2, $resultado['conjuntos'][0]['itens']);
        $this->assertSame('R$ 300,00', $resultado['conjuntos'][0]['preco_label']);
        $this->assertSame([$variacaoA, $variacaoB], array_column($resultado['conjuntos'][0]['itens'], 'produto_variacao_id'));
        $this->assertSame([$variacaoD], array_column($resultado['itens_avulsos'], 'id'));
    }

    public function test_modo_individual_expande_preco_por_item(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('conjuntos/hero-individual.jpg', 'hero');

        [$produtoA, $variacaoA] = $this->criarProdutoComVariacao('Cama', 'CAM-001', 1200.00, 2);
        [$produtoB, $variacaoB] = $this->criarProdutoComVariacao('Painel', 'PAI-002', 450.00, 1);

        $this->criarConjunto(
            nome: 'Conjunto Quarto',
            heroPath: 'conjuntos/hero-individual.jpg',
            precoModo: 'individual',
            principalVariacaoId: null,
            itens: [
                ['produto_variacao_id' => $variacaoA, 'label' => 'Cama', 'ordem' => 1],
                ['produto_variacao_id' => $variacaoB, 'label' => 'Painel', 'ordem' => 2],
            ]
        );

        $resultado = $this->service()->build($this->carregarProdutos([$produtoA, $produtoB]));

        $this->assertCount(1, $resultado['conjuntos']);
        $this->assertSame('individual', $resultado['conjuntos'][0]['preco_modo']);
        $this->assertSame('Preço por item', $resultado['conjuntos'][0]['preco_label']);
        $this->assertSame([1200.0, 450.0], array_column($resultado['conjuntos'][0]['itens'], 'preco'));
    }

    public function test_modo_apartir_usa_principal_quando_disponivel_e_fallback_quando_nao_esta_no_catalogo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('conjuntos/hero-apartir-a.jpg', 'hero');
        Storage::disk('public')->put('conjuntos/hero-apartir-b.jpg', 'hero');

        [$produtoA, $variacaoPrincipalDisponivel] = $this->criarProdutoComVariacao('Sofa Premium', 'SOP-001', 900.00, 1);
        [$produtoB, $variacaoComplemento] = $this->criarProdutoComVariacao('Chaise Premium', 'CHP-001', 700.00, 2);
        [$produtoC, $variacaoPrincipalIndisponivel] = $this->criarProdutoComVariacao('Cama King', 'CAM-900', 1800.00, 0);
        [$produtoD, $variacaoMenorPreco] = $this->criarProdutoComVariacao('Painel Slim', 'PAN-100', 350.00, 2);
        [$produtoE, $variacaoMaiorPreco] = $this->criarProdutoComVariacao('Criado Luxo', 'CRI-100', 420.00, 1);

        $this->criarConjunto(
            nome: 'Conjunto Sala Premium',
            heroPath: 'conjuntos/hero-apartir-a.jpg',
            precoModo: 'apartir',
            principalVariacaoId: $variacaoPrincipalDisponivel,
            itens: [
                ['produto_variacao_id' => $variacaoPrincipalDisponivel, 'label' => 'Sofa', 'ordem' => 1],
                ['produto_variacao_id' => $variacaoComplemento, 'label' => 'Chaise', 'ordem' => 2],
            ]
        );

        $this->criarConjunto(
            nome: 'Conjunto Quarto Fallback',
            heroPath: 'conjuntos/hero-apartir-b.jpg',
            precoModo: 'apartir',
            principalVariacaoId: $variacaoPrincipalIndisponivel,
            itens: [
                ['produto_variacao_id' => $variacaoPrincipalIndisponivel, 'label' => 'Cama', 'ordem' => 1],
                ['produto_variacao_id' => $variacaoMenorPreco, 'label' => 'Painel', 'ordem' => 2],
                ['produto_variacao_id' => $variacaoMaiorPreco, 'label' => 'Criado', 'ordem' => 3],
            ]
        );

        $resultado = $this->service()->build($this->carregarProdutos([
            $produtoA,
            $produtoB,
            $produtoC,
            $produtoD,
            $produtoE,
        ]));

        $labels = collect($resultado['conjuntos'])->pluck('preco_label')->all();

        $this->assertContains('A partir de R$ 900,00', $labels);
        $this->assertContains('A partir de R$ 350,00', $labels);
    }

    public function test_variacao_pode_aparecer_em_multiplos_conjuntos_renderizados(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('conjuntos/hero-multi-a.jpg', 'hero');
        Storage::disk('public')->put('conjuntos/hero-multi-b.jpg', 'hero');

        [$produtoA, $variacaoCompartilhada] = $this->criarProdutoComVariacao('Sofa Modular', 'SM-001', 800.00, 1);
        [$produtoB, $variacaoB] = $this->criarProdutoComVariacao('Chaise Modular', 'CM-001', 500.00, 1);
        [$produtoC, $variacaoC] = $this->criarProdutoComVariacao('Mesa Lateral', 'ML-001', 250.00, 1);

        $this->criarConjunto(
            nome: 'Conjunto A',
            heroPath: 'conjuntos/hero-multi-a.jpg',
            precoModo: 'soma',
            principalVariacaoId: null,
            itens: [
                ['produto_variacao_id' => $variacaoCompartilhada, 'label' => 'Sofa', 'ordem' => 1],
                ['produto_variacao_id' => $variacaoB, 'label' => 'Chaise', 'ordem' => 2],
            ]
        );

        $this->criarConjunto(
            nome: 'Conjunto B',
            heroPath: 'conjuntos/hero-multi-b.jpg',
            precoModo: 'soma',
            principalVariacaoId: null,
            itens: [
                ['produto_variacao_id' => $variacaoCompartilhada, 'label' => 'Sofa', 'ordem' => 1],
                ['produto_variacao_id' => $variacaoC, 'label' => 'Mesa', 'ordem' => 2],
            ]
        );

        $resultado = $this->service()->build($this->carregarProdutos([$produtoA, $produtoB, $produtoC]));

        $this->assertCount(2, $resultado['conjuntos']);
        $this->assertSame(
            [true, true],
            collect($resultado['conjuntos'])
                ->map(fn (array $card) => in_array($variacaoCompartilhada, array_column($card['itens'], 'produto_variacao_id'), true))
                ->all()
        );
        $this->assertSame([], array_column($resultado['itens_avulsos'], 'id'));
    }

    private function service(): OutletCatalogoPdfService
    {
        return app(OutletCatalogoPdfService::class);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function criarProdutoComVariacao(string $nomeProduto, string $referencia, float $preco, int $quantidadeRestante): array
    {
        $now = now();

        $produtoId = (int) DB::table('produtos')->insertGetId([
            'nome' => $nomeProduto . ' ' . uniqid(),
            'descricao' => 'Produto para teste',
            'id_categoria' => $this->categoriaId,
            'id_fornecedor' => $this->fornecedorId,
            'altura' => 100,
            'largura' => 200,
            'profundidade' => 90,
            'peso' => null,
            'manual_conservacao' => null,
            'estoque_minimo' => null,
            'ativo' => true,
            'motivo_desativacao' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $variacaoId = (int) DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => $referencia . '-' . uniqid(),
            'nome' => $nomeProduto,
            'preco' => $preco,
            'custo' => $preco / 2,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($quantidadeRestante > 0) {
            DB::table('produto_variacao_outlets')->insert([
                'produto_variacao_id' => $variacaoId,
                'motivo_id' => $this->motivoId,
                'quantidade' => $quantidadeRestante,
                'quantidade_restante' => $quantidadeRestante,
                'usuario_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [$produtoId, $variacaoId];
    }

    /**
     * @param array<int, int> $produtoIds
     * @return \Illuminate\Support\Collection<int, Produto>
     */
    private function carregarProdutos(array $produtoIds)
    {
        return Produto::query()
            ->whereIn('id', $produtoIds)
            ->with([
                'categoria',
                'variacoes.atributos',
                'variacoes.imagem',
                'variacoes.outlets',
            ])
            ->get();
    }

    /**
     * @param array<int, array{produto_variacao_id:int,label:?string,ordem:int}> $itens
     */
    private function criarConjunto(
        string $nome,
        string $heroPath,
        string $precoModo,
        ?int $principalVariacaoId,
        array $itens
    ): ProdutoConjunto {
        $conjunto = ProdutoConjunto::create([
            'nome' => $nome,
            'descricao' => 'Descricao do conjunto',
            'hero_image_path' => $heroPath,
            'preco_modo' => $precoModo,
            'principal_variacao_id' => $principalVariacaoId,
            'ativo' => true,
        ]);

        foreach ($itens as $item) {
            $conjunto->itens()->create($item);
        }

        return $conjunto;
    }
}
