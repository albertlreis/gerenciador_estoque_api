<?php

namespace Tests\Feature\Importacao;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportacaoPedidoFornecedorXmlExamplesTest extends TestCase
{
    use RefreshDatabase;

    public static function fornecedorXmlExamplesProvider(): array
    {
        return [
            'SIERRABELM__730759.xml' => ['SIERRABELM__730759.xml', 1],
            'SIERRABELM__738588.xml' => ['SIERRABELM__738588.xml', 7],
            'SIERRABELM__738589.xml' => ['SIERRABELM__738589.xml', 2],
            'SIERRABELM__730256.xml' => ['SIERRABELM__730256.xml', 8],
            'SIERRABELM__738599.xml' => ['SIERRABELM__738599.xml', 3],
        ];
    }

    /**
     * @dataProvider fornecedorXmlExamplesProvider
     */
    public function test_importa_todos_exemplos_xml_de_fornecedores_com_contagem_exata(
        string $fileName,
        int $expectedItems
    ): void {
        $usuario = Usuario::create([
            'nome' => 'Usuario XML Fornecedor',
            'email' => 'xml-fornecedor-' . md5($fileName) . '@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $path = base_path('docs/' . $fileName);
        $this->assertFileExists($path);

        $file = new UploadedFile($path, $fileName, 'application/xml', null, true);

        $response = $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/pedidos/import', [
                'tipo_importacao' => 'PRODUTOS_XML_FORNECEDORES',
                'arquivo' => $file,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('sucesso', true);
        $response->assertJsonPath('dados.pedido.numero_externo', '');

        $itens = $response->json('dados.itens') ?? [];
        $this->assertCount($expectedItems, $itens, 'Contagem de itens divergente para ' . $fileName);

        foreach ($itens as $idx => $item) {
            $descricao = trim((string) ($item['descricao'] ?? $item['nome'] ?? ''));
            $quantidade = (float) ($item['quantidade'] ?? 0);
            $valorUnitario = (float) ($item['preco_unitario'] ?? $item['preco'] ?? 0);

            $this->assertNotSame('', $descricao, "Descricao vazia no item {$idx} ({$fileName})");
            $this->assertGreaterThan(0, $quantidade, "Quantidade invalida no item {$idx} ({$fileName})");
            $this->assertGreaterThanOrEqual(0, $valorUnitario, "Valor unitario invalido no item {$idx} ({$fileName})");
            $this->assertNotSame('', trim((string) ($item['ref'] ?? '')), "Referencia vazia no item {$idx} ({$fileName})");
        }
    }

    public function test_importa_xml_fornecedor_com_numeros_em_formato_brasileiro(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario XML Fornecedor BR',
            'email' => 'xml-fornecedor-br@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $path = $this->tempXmlPath($this->xmlFornecedorNumerosBrasileiros());
        $file = new UploadedFile($path, 'SIERRABELM__BR.xml', 'application/xml', null, true);

        try {
            $response = $this->actingAs($usuario, 'sanctum')
                ->post('/api/v1/pedidos/import', [
                    'tipo_importacao' => 'PRODUTOS_XML_FORNECEDORES',
                    'arquivo' => $file,
                ]);
        } finally {
            @unlink($path);
        }

        $response->assertStatus(200);
        $response->assertJsonPath('sucesso', true);
        $response->assertJsonPath('dados.itens.0.quantidade', '2');
        $response->assertJsonPath('dados.itens.0.preco_unitario', '1234.56');
        $response->assertJsonPath('dados.itens.0.valor_total_linha', '2469.12');
    }

    public function test_importa_model_como_atributos_tipados_sem_alterar_o_raw(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario XML Acabamentos',
            'email' => 'xml-acabamentos@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);
        $modelo = 'AC25#E-13233 - OFF WHITE#MESMA COR DO TECIDO#I-24604';
        $path = $this->tempXmlPath(<<<XML
<?xml version="1.0" encoding="utf-8"?>
<LISTING>
  <NUMERO_PEDIDO>ACAB-001</NUMERO_PEDIDO>
  <ITEMS>
    <ITEM DESCRIPTION="CAMA DAMIANI KING" QUANTITY="1" PRICE="13913.62">
      <REFERENCES>
        <CODE REFERENCE="3545K"/>
        <MODEL REFERENCE="{$modelo}"/>
      </REFERENCES>
    </ITEM>
  </ITEMS>
</LISTING>
XML);
        $file = new UploadedFile($path, 'acabamentos.xml', 'application/xml', null, true);

        try {
            $response = $this->actingAs($usuario, 'sanctum')
                ->post('/api/v1/pedidos/import', [
                    'tipo_importacao' => 'PRODUTOS_XML_FORNECEDORES',
                    'arquivo' => $file,
                ]);
        } finally {
            @unlink($path);
        }

        $response->assertStatus(200);
        foreach (['atributos', 'atributos_detectados'] as $campo) {
            $response->assertJsonMissingPath("dados.itens.0.{$campo}.acabamentos");
            $response->assertJsonPath("dados.itens.0.{$campo}.madeira", 'AC25');
            $response->assertJsonPath("dados.itens.0.{$campo}.tecido_1", 'E-13233 - OFF WHITE');
            $response->assertJsonPath("dados.itens.0.{$campo}.tecido_2", 'I-24604');
        }
        $esperados = [
            ['atributo' => 'madeira', 'valor' => 'AC25'],
            ['atributo' => 'tecido_1', 'valor' => 'E-13233 - OFF WHITE'],
            ['atributo' => 'tecido_2', 'valor' => 'MESMA COR DO TECIDO'],
            ['atributo' => 'tecido_2', 'valor' => 'I-24604'],
        ];
        foreach (['atributos_lista', 'atributos_detectados_lista'] as $campo) {
            $response->assertJsonPath("dados.itens.0.{$campo}", $esperados);
        }
        $response->assertJsonPath('dados.itens.0.atributos_raw.0.nome', 'modelo_referencia');
        $response->assertJsonPath('dados.itens.0.atributos_raw.0.valor', $modelo);
    }

    public function test_rejeita_tipo_de_importacao_nao_suportado(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Tipo Antigo',
            'email' => 'xml-tipo-antigo@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $path = base_path('docs/SIERRABELM__730759.xml');
        $file = new UploadedFile($path, 'SIERRABELM__730759.xml', 'application/xml', null, true);

        $response = $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/pedidos/import', [
                'tipo_importacao' => 'PRODUTOS_IMPORTACAO_DESCONHECIDA',
                'arquivo' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('sucesso', false);
    }

    private function tempXmlPath(string $content): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pedido-fornecedor-' . uniqid('', true) . '.xml';
        file_put_contents($path, $content);

        return $path;
    }

    private function xmlFornecedorNumerosBrasileiros(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<LISTING>
  <NUMERO_PEDIDO>BR-001</NUMERO_PEDIDO>
  <CLIENTE_PEDIDO>Cliente BR</CLIENTE_PEDIDO>
  <ORDEM_COMPRA_PEDIDO>OC-BR</ORDEM_COMPRA_PEDIDO>
  <LOJA_PEDIDO>Sierra Belem</LOJA_PEDIDO>
  <FORNECEDOR_PEDIDO>Fornecedor BR</FORNECEDOR_PEDIDO>
  <FORNECEDOR_CNPJ_PEDIDO>11222333000144</FORNECEDOR_CNPJ_PEDIDO>
  <ITEMS>
    <ITEM DESCRIPTION="Produto formato BR" QUANTITY="2,0000" PRICE="1.234,56">
      <REFERENCES>
        <CODE REFERENCE="REF-BR-001"/>
        <MODEL REFERENCE="MODELO-BR"/>
      </REFERENCES>
    </ITEM>
  </ITEMS>
</LISTING>
XML;
    }
}
