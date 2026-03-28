<?php

namespace Tests\Feature\Importacao;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportacaoPedidoPdfExamplesTest extends TestCase
{
    use RefreshDatabase;

    public static function fornecedorXmlExamplesProvider(): array
    {
        return [
            'SIERRABELM__730759.xml' => ['SIERRABELM__730759.xml', 1, '780'],
            'SIERRABELM__738588.xml' => ['SIERRABELM__738588.xml', 7, '787'],
            'SIERRABELM__738589.xml' => ['SIERRABELM__738589.xml', 2, '788'],
            'SIERRABELM__730256.xml' => ['SIERRABELM__730256.xml', 8, '776'],
            'SIERRABELM__738599.xml' => ['SIERRABELM__738599.xml', 3, '794'],
        ];
    }

    /**
     * @dataProvider fornecedorXmlExamplesProvider
     */
    public function test_importa_todos_exemplos_xml_de_fornecedores_com_contagem_exata(
        string $fileName,
        int $expectedItems,
        string $expectedNumero
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
        $response->assertJsonPath('dados.pedido.numero_externo', $expectedNumero);

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

    public function test_rejeita_tipo_antigo_de_importacao_pdf(): void
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
                'tipo_importacao' => 'PRODUTOS_PDF_SIERRA',
                'arquivo' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('sucesso', false);
    }
}
