<?php

namespace Tests\Unit;

use App\Services\NfeXmlParserService;
use App\Services\SierraNfeProductDescriptionParser;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

class NfeXmlParserServiceSierraTest extends TestCase
{
    public function test_aplica_interpretacao_sierra_sem_alterar_item_sem_regra_segura(): void
    {
        $path = dirname(__DIR__).'/Fixtures/nfe-sierra-atributos-ficticia.xml';
        $arquivo = new UploadedFile($path, basename($path), 'application/xml', null, true);
        $service = new NfeXmlParserService(new SierraNfeProductDescriptionParser);

        $resultado = $service->extrair($arquivo);
        $itemIdentificado = $resultado['itens'][0];
        $itemSemRegra = $resultado['itens'][1];

        $this->assertSame('5330', $itemIdentificado['codigo']);
        $this->assertSame('5330', $itemIdentificado['ref']);
        $this->assertSame('5330[244271425]', $itemIdentificado['codigo_origem']);
        $this->assertSame('POLTRONA NIDUS', $itemIdentificado['nome']);
        $this->assertSame(
            'POLTRONA NIDUS CORAC03 TECI-24805 PESPMESMA COR DO TECIDO',
            $itemIdentificado['descricao']
        );
        $this->assertSame([
            'madeira' => 'AC03',
            'tecido_1' => 'I-24805',
            'pes' => 'Mesma cor do tecido',
        ], $itemIdentificado['atributos']);
        $this->assertSame($itemIdentificado['atributos'], $itemIdentificado['atributos_detectados']);
        $this->assertSame([
            ['atributo' => 'madeira', 'valor' => 'AC03'],
            ['atributo' => 'tecido_1', 'valor' => 'I-24805'],
            ['atributo' => 'pes', 'valor' => 'Mesma cor do tecido'],
        ], $itemIdentificado['atributos_lista']);
        $this->assertSame(
            $itemIdentificado['atributos_lista'],
            $itemIdentificado['atributos_detectados_lista']
        );

        $this->assertSame('TESTE', $itemSemRegra['codigo']);
        $this->assertSame('TESTE', $itemSemRegra['ref']);
        $this->assertSame('TESTE[SEM-ATRIBUTO]', $itemSemRegra['codigo_origem']);
        $this->assertSame('SOFÁ CORAL DECORATIVO', $itemSemRegra['nome']);
        $this->assertSame([], $itemSemRegra['atributos']);
        $this->assertSame([], $itemSemRegra['atributos_detectados']);
        $this->assertSame([], $itemSemRegra['atributos_lista']);
        $this->assertSame([], $itemSemRegra['atributos_detectados_lista']);
    }
}
