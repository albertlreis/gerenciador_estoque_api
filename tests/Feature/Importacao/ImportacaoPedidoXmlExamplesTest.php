<?php

namespace Tests\Feature\Importacao;

use App\Models\PedidoImportacao;
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

        foreach ($itens as $idx => $item) {
            $descricao = trim((string) ($item['descricao'] ?? $item['nome'] ?? ''));
            $quantidade = (float) ($item['quantidade'] ?? 0);
            $valorUnitario = (float) ($item['valor_unitario'] ?? $item['preco_unitario'] ?? $item['preco'] ?? 0);
            $valorTotalLinha = (float) ($item['valor_total_linha'] ?? $item['valor_total'] ?? 0);

            $this->assertNotSame('', $descricao, "Descricao vazia no item {$idx} ({$entry['file']})");
            $this->assertGreaterThan(0, $quantidade, "Quantidade invalida no item {$idx} ({$entry['file']})");
            $this->assertGreaterThanOrEqual(0, $valorUnitario, "Valor unitario invalido no item {$idx} ({$entry['file']})");
            $this->assertGreaterThanOrEqual(0, $valorTotalLinha, "Valor total invalido no item {$idx} ({$entry['file']})");

            $identificadores = [
                trim((string) ($item['codigo'] ?? '')),
                trim((string) ($item['ref'] ?? '')),
                trim((string) ($item['referencia'] ?? '')),
                trim((string) ($item['codigo_barras'] ?? '')),
            ];
            $temIdentificador = false;
            foreach ($identificadores as $id) {
                if ($id !== '') {
                    $temIdentificador = true;
                    break;
                }
            }
            $this->assertTrue($temIdentificador, "Item {$idx} sem identificador ({$entry['file']})");
        }
    }

    public function test_preview_xml_reutiliza_importacao_sem_duplicar(): void
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

        $first->assertStatus(200);
        $firstImportacaoId = $first->json('importacao_id');
        $this->assertNotNull($firstImportacaoId);

        $file2 = new UploadedFile($path, basename($path), 'application/xml', null, true);
        $second = $this->actingAs($usuario, 'sanctum')->post('/api/v1/pedidos/import', [
            'tipo_importacao' => $entry['tipo_importacao'],
            'arquivo' => $file2,
        ]);

        $second->assertStatus(200);
        $second->assertJsonPath('mensagem', 'Arquivo já processado. Usando dados existentes.');
        $this->assertSame($firstImportacaoId, $second->json('importacao_id'));

        $hashArquivo = hash_file('sha256', $path);
        $hash = hash('sha256', $hashArquivo . '|' . $entry['tipo_importacao']);
        $this->assertSame(1, PedidoImportacao::query()->where('arquivo_hash', $hash)->count());
    }
}
