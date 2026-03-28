<?php

namespace Tests\Feature\Importacao;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportacaoPedidoXmlExamplesTest extends TestCase
{
    use RefreshDatabase;

    private static function manifest(): array
    {
        $path = dirname(__DIR__, 3) . '/tests/Fixtures/Importacao/manifest.json';
        $json = file_get_contents($path);
        return is_string($json) ? (json_decode($json, true) ?: []) : [];
    }

    public static function xmlExamplesProvider(): array
    {
        $manifest = self::manifest();
        $files = $manifest['files'] ?? [];

        $cases = [];
        foreach ($files as $entry) {
            if (($entry['type'] ?? null) !== 'xml') {
                continue;
            }
            $cases[$entry['file']] = [$entry];
        }

        return $cases;
    }

    /**
     * @dataProvider xmlExamplesProvider
     */
    public function test_importa_todos_exemplos_xml_com_contagem_exata(array $entry): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario XML',
            'email' => 'xml-' . md5($entry['file']) . '@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $path = base_path('tests/Fixtures/Importacao/' . $entry['file']);
        $this->assertFileExists($path);

        $file = new UploadedFile($path, basename($path), 'application/xml', null, true);

        $response = $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/pedidos/import', [
                'tipo_importacao' => $entry['tipo_importacao'],
                'arquivo' => $file,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('sucesso', true);

        $itens = $response->json('dados.itens') ?? [];
        $this->assertCount((int) $entry['expected_items'], $itens, 'Contagem de itens divergente para ' . $entry['file']);
    }

    public function test_reimportacao_xml_gera_novo_preview(): void
    {
        $entry = self::xmlExamplesProvider()['Xml/nfe-35250207.xml'][0];

        $usuario = Usuario::create([
            'nome' => 'Usuario Preview XML',
            'email' => 'xml-preview@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $path = base_path('tests/Fixtures/Importacao/' . $entry['file']);
        $this->assertFileExists($path);

        $file1 = new UploadedFile($path, basename($path), 'application/xml', null, true);
        $first = $this->actingAs($usuario, 'sanctum')->post('/api/v1/pedidos/import', [
            'tipo_importacao' => $entry['tipo_importacao'],
            'arquivo' => $file1,
        ]);

        $file2 = new UploadedFile($path, basename($path), 'application/xml', null, true);
        $second = $this->actingAs($usuario, 'sanctum')->post('/api/v1/pedidos/import', [
            'tipo_importacao' => $entry['tipo_importacao'],
            'arquivo' => $file2,
        ]);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertNotSame($first->json('importacao_id'), $second->json('importacao_id'));
    }
}
