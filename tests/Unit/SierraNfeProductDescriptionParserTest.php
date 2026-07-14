<?php

namespace Tests\Unit;

use App\Services\SierraNfeProductDescriptionParser;
use PHPUnit\Framework\TestCase;

class SierraNfeProductDescriptionParserTest extends TestCase
{
    private SierraNfeProductDescriptionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new SierraNfeProductDescriptionParser;
    }

    public function test_reconhece_emitente_pela_raiz_do_cnpj_ou_nome_normalizado(): void
    {
        $this->assertTrue($this->parser->suportaEmitente('92.726.785/0099-99', 'Outro nome'));
        $this->assertTrue($this->parser->suportaEmitente(null, 'Sierra Móveis Ltda.'));
        $this->assertTrue($this->parser->suportaEmitente(null, 'Móveis Sierra S/A'));
        $this->assertFalse($this->parser->suportaEmitente('11.222.333/0001-44', 'Sierra Home'));
        $this->assertFalse($this->parser->suportaEmitente('11.222.333/0001-44', 'Fornecedor comum'));
    }

    public function test_interpreta_exemplo_da_poltrona_nidus(): void
    {
        $resultado = $this->parser->interpretar(
            '5330[244271425]',
            'POLTRONA NIDUS CORAC03 TECI-24805 PESPMESMA COR DO TECIDO'
        );

        $this->assertTrue($resultado['identificado']);
        $this->assertSame('5330', $resultado['codigo']);
        $this->assertSame('5330', $resultado['ref']);
        $this->assertSame('5330[244271425]', $resultado['codigo_origem']);
        $this->assertSame('POLTRONA NIDUS', $resultado['nome']);
        $this->assertSame(
            'POLTRONA NIDUS CORAC03 TECI-24805 PESPMESMA COR DO TECIDO',
            $resultado['descricao']
        );
        $this->assertSame([
            'madeira' => 'AC03',
            'tecido_1' => 'I-24805',
            'pes' => 'Mesma cor do tecido',
        ], $resultado['atributos']);
        $this->assertSame($resultado['atributos'], $resultado['atributos_detectados']);
        $this->assertSame([
            ['atributo' => 'madeira', 'valor' => 'AC03'],
            ['atributo' => 'tecido_1', 'valor' => 'I-24805'],
            ['atributo' => 'pes', 'valor' => 'Mesma cor do tecido'],
        ], $resultado['atributos_lista']);
        $this->assertSame($resultado['atributos_lista'], $resultado['atributos_detectados_lista']);
    }

    public function test_preserva_atributos_repetidos_na_lista_e_mantem_ultima_ocorrencia_no_mapa(): void
    {
        $resultado = $this->parser->interpretar(
            '5330[DUAS-MADEIRAS]',
            'POLTRONA NIDUS CORAC03 CORMT31 - PRETO TECI-24805'
        );

        $this->assertSame([
            ['atributo' => 'madeira', 'valor' => 'AC03'],
            ['atributo' => 'madeira', 'valor' => 'MT31 - PRETO'],
            ['atributo' => 'tecido_1', 'valor' => 'I-24805'],
        ], $resultado['atributos_lista']);
        $this->assertSame('MT31 - PRETO', $resultado['atributos']['madeira']);
        $this->assertSame($resultado['atributos_lista'], $resultado['atributos_detectados_lista']);
        $this->assertSame($resultado['atributos'], $resultado['atributos_detectados']);
    }

    public function test_interpreta_madeira_metal_tecidos_e_detalhes_secundarios(): void
    {
        $resultado = $this->parser->interpretar(
            '7000[CONFIG]',
            'BANQUETA RUGBY CORAD 29 COR INOXONIX FOSCO - ON 03F '
            .'TECIDOE-13241 ENCOSTOL-18042 DEBRUML-18042 PESPMESMA COR DO TECIDO'
        );

        $this->assertSame('BANQUETA RUGBY', $resultado['nome']);
        $this->assertSame('AD29', $resultado['atributos']['madeira']);
        $this->assertSame('Inox: Onix Fosco ON 03F', $resultado['atributos']['metal_vidro']);
        $this->assertSame('E-13241', $resultado['atributos']['tecido_1']);
        $this->assertSame(
            'Encosto: L-18042 · Debrum: L-18042',
            $resultado['atributos']['tecido_2']
        );
        $this->assertSame('Mesma cor do tecido', $resultado['atributos']['pes']);
    }

    public function test_prioriza_codigo_substituto_e_ignora_qualificador_tecido_unico(): void
    {
        $resultado = $this->parser->interpretar(
            '8100[CONFIG]',
            'SOFÁ LUNA TECN-24950 - ALTEROU PARA L-24950 EM 01/07 TECIDO ÚNICO'
        );

        $this->assertSame('SOFÁ LUNA', $resultado['nome']);
        $this->assertSame('L-24950', $resultado['atributos']['tecido_1']);
        $this->assertCount(1, $resultado['atributos']);
    }

    public function test_aceita_fornecido_como_valor_de_detalhe_secundario(): void
    {
        $resultado = $this->parser->interpretar(
            '8200[CONFIG]',
            'POLTRONA TESTE ENCOSTOFORNECIDO'
        );

        $this->assertSame('Encosto: FORNECIDO', $resultado['atributos']['tecido_2']);
    }

    public function test_interpreta_marcadores_compactos_de_couro_metal_e_detalhes(): void
    {
        $resultado = $this->parser->interpretar(
            '8300[CONFIG]',
            'CADEIRA TESTE CORMT 31 - PRETO COR ALUMINIOBEGE COUROB-03 '
            .'DET. TECIDOD-12341 TIRAS PVCP-123 BRAÇOB-456 ESTRUTURAE-789 TELAT-101'
        );

        $this->assertSame('MT31 - PRETO', $resultado['atributos']['madeira']);
        $this->assertSame('Alumínio: Bege', $resultado['atributos']['metal_vidro']);
        $this->assertSame('B-03', $resultado['atributos']['tecido_1']);
        $this->assertSame(
            'Det. Tecido: D-12341 · Tiras PVC: P-123 · Braço: B-456 '
            .'· Estrutura: E-789 · Tela: T-101',
            $resultado['atributos']['tecido_2']
        );
    }

    /** @dataProvider tecidosPrincipaisProvider */
    public function test_interpreta_variantes_de_tecido_principal(string $descricao, string $esperado): void
    {
        $resultado = $this->parser->interpretar('8400[CONFIG]', $descricao);

        $this->assertSame($esperado, $resultado['atributos']['tecido_1']);
    }

    public function tecidosPrincipaisProvider(): array
    {
        return [
            'tecido compacto' => ['SOFÁ TESTE TECL-18052', 'L-18052'],
            'couro compacto' => ['SOFÁ TESTE COUROB-03', 'B-03'],
            'assento compacto' => ['SOFÁ TESTE ASSL-18049', 'L-18049'],
            'valor fornecido' => ['SOFÁ TESTE TECIDOFORNECIDO', 'FORNECIDO'],
        ];
    }

    public function test_nao_infere_dimensoes_e_evitar_falsos_positivos(): void
    {
        $comDimensoes = $this->parser->interpretar(
            '9000[CONFIG]',
            'MESA TESTE 152 X 53 X 37 CM CORGV05 - NATURAL'
        );
        $semMarcadorSeguro = $this->parser->interpretar(
            '9001[ORIGINAL]',
            'SOFÁ CORAL DECORATIVO CORPRIMAVERA TECIDO ÚNICO'
        );

        $this->assertSame(['madeira' => 'GV05 - NATURAL'], $comDimensoes['atributos']);
        $this->assertArrayNotHasKey('largura', $comDimensoes['atributos']);
        $this->assertArrayNotHasKey('profundidade', $comDimensoes['atributos']);
        $this->assertArrayNotHasKey('altura', $comDimensoes['atributos']);

        $this->assertFalse($semMarcadorSeguro['identificado']);
        $this->assertSame('9001', $semMarcadorSeguro['codigo']);
        $this->assertSame('9001', $semMarcadorSeguro['ref']);
        $this->assertSame('9001[ORIGINAL]', $semMarcadorSeguro['codigo_origem']);
        $this->assertSame('SOFÁ CORAL DECORATIVO CORPRIMAVERA TECIDO ÚNICO', $semMarcadorSeguro['nome']);
        $this->assertSame([], $semMarcadorSeguro['atributos']);
    }
}
