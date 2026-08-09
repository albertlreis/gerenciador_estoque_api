<?php

namespace Tests\Unit;

use App\Services\FornecedorModeloAtributosService;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

class FornecedorModeloAtributosServiceTest extends TestCase
{
    private FornecedorModeloAtributosService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FornecedorModeloAtributosService();
    }

    /**
     * @dataProvider modelosProvider
     */
    public function test_classifica_model_por_tipo_sem_perder_segmentos(string $modelo, array $esperado): void
    {
        $this->assertSame($esperado, $this->service->classificar($modelo));
    }

    public static function modelosProvider(): array
    {
        return [
            'madeira metal e tecidos repetidos' => [
                'AC25#E-13233 - OFF WHITE#MESMA COR DO TECIDO#I-24604',
                [
                    ['atributo' => 'madeira', 'valor' => 'AC25'],
                    ['atributo' => 'tecido_1', 'valor' => 'E-13233 - OFF WHITE'],
                    ['atributo' => 'tecido_2', 'valor' => 'MESMA COR DO TECIDO'],
                    ['atributo' => 'tecido_2', 'valor' => 'I-24604'],
                ],
            ],
            'madeira e metal' => [
                'AC03#GOLD FOSCO - GO 01F',
                [
                    ['atributo' => 'madeira', 'valor' => 'AC03'],
                    ['atributo' => 'metal_vidro', 'valor' => 'GOLD FOSCO - GO 01F'],
                ],
            ],
            'marcadores nao ocupam tecido primario' => [
                'TECIDO UNICO#D-24004#MESMA COR DO TECIDO',
                [
                    ['atributo' => 'tecido_2', 'valor' => 'TECIDO UNICO'],
                    ['atributo' => 'tecido_1', 'valor' => 'D-24004'],
                    ['atributo' => 'tecido_2', 'valor' => 'MESMA COR DO TECIDO'],
                ],
            ],
            'madeira material secundario e tecido' => [
                'AD 02#CAR 09 - AREIA#G-14150',
                [
                    ['atributo' => 'madeira', 'valor' => 'AD 02'],
                    ['atributo' => 'tecido_2', 'valor' => 'CAR 09 - AREIA'],
                    ['atributo' => 'tecido_1', 'valor' => 'G-14150'],
                ],
            ],
            'rotulos explicitos' => [
                'COR: AC25#INOX: GOLD GO01#TEC: D-24004',
                [
                    ['atributo' => 'madeira', 'valor' => 'AC25'],
                    ['atributo' => 'metal_vidro', 'valor' => 'INOX: GOLD GO01'],
                    ['atributo' => 'tecido_1', 'valor' => 'D-24004'],
                ],
            ],
            'pedra como material secundario' => [
                'AD 02#TRAVERTINO',
                [
                    ['atributo' => 'madeira', 'valor' => 'AD 02'],
                    ['atributo' => 'tecido_2', 'valor' => 'TRAVERTINO'],
                ],
            ],
        ];
    }

    public function test_fallback_posicional_preserva_segmentos_vazios_e_adicionais(): void
    {
        $this->assertSame([
            ['atributo' => 'madeira', 'valor' => 'A DEFINIR'],
            ['atributo' => 'metal_vidro', 'valor' => 'SEM TIPO'],
            ['atributo' => 'tecido_1', 'valor' => 'OUTRO'],
            ['atributo' => 'tecido_2', 'valor' => 'QUARTO'],
            ['atributo' => 'tecido_2', 'valor' => 'QUINTO'],
        ], $this->service->classificar(' A DEFINIR ## SEM TIPO # OUTRO # QUARTO # QUINTO '));
    }

    public function test_todos_os_models_dos_xmls_reais_sao_tipados_sem_perda(): void
    {
        $arquivos = glob(dirname(__DIR__, 2).'/docs/SIERRABELM__*.xml') ?: [];
        $this->assertNotEmpty($arquivos);

        foreach ($arquivos as $arquivo) {
            $dom = new DOMDocument();
            $this->assertTrue($dom->load($arquivo), "XML invalido: {$arquivo}");
            $xpath = new DOMXPath($dom);

            foreach ($xpath->query('/LISTING/ITEMS/ITEM/REFERENCES/MODEL/@REFERENCE') ?: [] as $node) {
                $modelo = trim((string) $node->nodeValue);
                $segmentos = array_values(array_filter(array_map(
                    static fn (string $parte) => preg_replace('/\s+/u', ' ', trim($parte)) ?: '',
                    explode('#', $modelo)
                ), static fn (string $parte) => $parte !== ''));
                $classificados = $this->service->classificar($modelo);

                $this->assertCount(count($segmentos), $classificados, "Perda de segmento em {$arquivo}: {$modelo}");
                $this->assertSame($segmentos, array_column($classificados, 'valor'));
                foreach ($classificados as $atributo) {
                    $this->assertContains($atributo['atributo'], ['madeira', 'metal_vidro', 'tecido_1', 'tecido_2']);
                }
            }
        }
    }
}
