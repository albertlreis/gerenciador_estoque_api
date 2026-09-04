<?php

namespace Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class CatalogosPdfCategoriaViewTest extends TestCase
{
    private static ?Application $app = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        self::$app->make(Kernel::class)->bootstrap();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        self::$app = null;

        parent::tearDownAfterClass();
    }

    public function test_catalogo_produtos_mantem_uma_categoria_por_linha_e_duas_linhas_por_pagina(): void
    {
        $cards = [
            $this->produtoCard('Mesa Beta', 'Mesas', 'M-2'),
            $this->produtoCard('Cadeira Zeta', 'Cadeiras', 'C-2'),
            $this->produtoCard('Mesa Alfa', 'Mesas', 'M-1'),
            $this->produtoCard('Cadeira Alfa', 'Cadeiras', 'C-1'),
            $this->produtoCard('Cadeira Gama', 'Cadeiras', 'C-3'),
        ];

        $html = view('exports.catalogo-produtos', [
            'cards' => $cards,
            'generatedAt' => '03/09/2026 10:00',
        ])->render();

        $this->assertSame(1, substr_count($html, '<div class="page">'));
        $this->assertSame(1, substr_count($html, '<div class="category-band">Cadeiras</div>'));
        $this->assertSame(1, substr_count($html, '<div class="category-band">Mesas</div>'));
        $this->assertSame(2, substr_count($html, 'class="product-row"'));
        $this->assertStringContainsString('class="product-row" data-category="Cadeiras"', $html);
        $this->assertStringContainsString('class="product-row" data-category="Mesas"', $html);
        $this->assertTrue(strpos($html, 'Cadeira Alfa') < strpos($html, 'Cadeira Zeta'));
        $this->assertTrue(strpos($html, 'Cadeira Zeta') < strpos($html, 'Mesa Alfa'));
    }

    public function test_catalogo_produtos_pagina_por_duas_linhas_e_repete_categoria_na_continuacao(): void
    {
        $cenarios = [
            1 => ['paginas' => 1, 'linhas' => 1],
            4 => ['paginas' => 1, 'linhas' => 1],
            5 => ['paginas' => 1, 'linhas' => 2],
            8 => ['paginas' => 1, 'linhas' => 2],
            9 => ['paginas' => 2, 'linhas' => 3],
        ];

        foreach ($cenarios as $quantidade => $esperado) {
            $cards = [];
            foreach (range(1, $quantidade) as $indice) {
                $cards[] = $this->produtoCard(
                    'Sofa '.str_pad((string) $indice, 2, '0', STR_PAD_LEFT),
                    'Sofas',
                    'S-'.$indice
                );
            }

            $html = view('exports.catalogo-produtos', [
                'cards' => $cards,
                'generatedAt' => '04/09/2026 10:00',
            ])->render();

            $this->assertSame($esperado['paginas'], substr_count($html, '<div class="page">'));
            $this->assertSame($esperado['linhas'], substr_count($html, 'class="product-row"'));
            $this->assertSame($quantidade, substr_count($html, '<div class="card">'));
        }

        $this->assertSame(2, substr_count($html, '<div class="category-band">Sofas</div>'));
    }

    public function test_catalogo_produtos_nao_reaproveita_espaco_da_categoria_anterior(): void
    {
        $cards = [
            $this->produtoCard('Adorno Unico', 'Adornos', 'A-1'),
            $this->produtoCard('Tapete Alfa', 'Tapetes', 'T-1'),
            $this->produtoCard('Tapete Beta', 'Tapetes', 'T-2'),
            $this->produtoCard('Tapete Gama', 'Tapetes', 'T-3'),
        ];

        $html = view('exports.catalogo-produtos', [
            'cards' => $cards,
            'generatedAt' => '04/09/2026 10:00',
        ])->render();

        $this->assertSame(1, substr_count($html, '<div class="page">'));
        $this->assertStringContainsString('style="width: 25%; margin-left: auto; margin-right: auto;"', $html);
        $this->assertStringContainsString('style="width: 75%; margin-left: auto; margin-right: auto;"', $html);
        $this->assertSame(2, substr_count($html, 'class="product-row"'));
    }

    public function test_catalogo_outlet_ordena_conjunto_antes_de_avulso_e_separa_categorias(): void
    {
        $cards = [
            $this->outletCard('Mesa Beta', 'Mesas', 'avulso'),
            $this->outletCard('Cadeira Avulsa', 'Cadeiras', 'avulso'),
            $this->outletCard('Conjunto Sala', 'Cadeiras', 'conjunto'),
            $this->outletCard('Mesa Alfa', 'Mesas', 'avulso'),
            $this->outletCard('Mesa Gama', 'Mesas', 'avulso'),
        ];

        $html = view('exports.outlet-catalogo', [
            'cards' => $cards,
            'generatedAt' => '03/09/2026 10:00',
        ])->render();

        $this->assertSame(2, substr_count($html, '<div class="page">'));
        $this->assertSame(1, substr_count($html, '<div class="category-band">Cadeiras</div>'));
        $this->assertSame(2, substr_count($html, '<div class="category-band">Mesas</div>'));
        $this->assertTrue(strpos($html, 'Conjunto Sala') < strpos($html, 'Cadeira Avulsa'));
        $this->assertTrue(strpos($html, 'Cadeira Avulsa') < strpos($html, 'Mesa Alfa'));
    }

    private function produtoCard(string $nome, string $categoria, string $referencia): array
    {
        return [
            'nome' => $nome,
            'categoria_nome' => $categoria,
            'referencia' => $referencia,
            'altura' => null,
            'largura' => null,
            'profundidade' => null,
            'atributos' => [],
            'preco_label' => 'R$ 100,00',
            'preco_sob_consulta' => false,
            'preco_original_label' => null,
            'pagamento_label' => null,
            'is_outlet' => false,
            'disponivel' => true,
            'imagem_src' => '',
        ];
    }

    private function outletCard(string $nome, string $categoria, string $tipo): array
    {
        $card = [
            'tipo' => $tipo,
            'nome' => $nome,
            'categoria_nome' => $categoria,
            'referencia' => 'REF-'.$nome,
            'altura' => null,
            'largura' => null,
            'profundidade' => null,
            'imagem_src' => null,
            'preco_label' => 'R$ 100,00',
            'preco_original_label' => null,
            'percentual_desconto' => 0,
            'pagamento_label' => null,
            'qtd_total_restante' => 1,
            'atributos_acabamentos' => [],
        ];

        if ($tipo === 'conjunto') {
            $card['descricao'] = 'Conjunto de teste';
            $card['preco_modo'] = 'soma';
            $card['itens'] = [];
        }

        return $card;
    }
}
