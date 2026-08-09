<?php

namespace Tests\Feature;

use App\Models\PedidoImportacao;
use App\Models\Usuario;
use App\Services\FornecedorPedidoXmlParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PedidoImportacaoXmlOriginalTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $email): Usuario
    {
        return Usuario::create([
            'nome' => 'Importador XML',
            'email' => $email,
            'senha' => 'teste',
            'ativo' => 1,
        ]);
    }

    private function permitirDownload(int $usuarioId): void
    {
        Cache::put("permissoes_usuario_{$usuarioId}", ['pedidos.importar_pdf'], now()->addHour());
    }

    public function test_upload_salva_bytes_metadados_e_checksum_do_xml(): void
    {
        Storage::fake('local');
        $usuario = $this->usuario('xml-original@example.com');
        $conteudo = '<?xml version="1.0"?><LISTING><NUMERO_PEDIDO>123</NUMERO_PEDIDO><TOTAL_LIQUIDO>10,00</TOTAL_LIQUIDO></LISTING>';

        $this->mock(FornecedorPedidoXmlParserService::class, function ($mock): void {
            $mock->shouldReceive('extrair')->once()->andReturn([
                'pedido' => ['numero_pedido' => '123', 'cliente' => 'Cliente'],
                'itens' => [],
                'totais' => ['total_liquido' => '10,00'],
            ]);
        });

        $response = $this->actingAs($usuario, 'sanctum')->post('/api/v1/pedidos/import', [
            'tipo_importacao' => 'PRODUTOS_XML_FORNECEDORES',
            'arquivo' => UploadedFile::fake()->createWithContent('pedido.xml', $conteudo),
        ]);

        $response->assertOk()->assertJsonPath('sucesso', true);
        $importacao = PedidoImportacao::query()->latest('id')->firstOrFail();
        $this->assertSame('extraido', $importacao->status);
        $this->assertSame(hash('sha256', $conteudo), $importacao->arquivo_hash_conteudo);
        $this->assertSame(strlen($conteudo), $importacao->arquivo_tamanho);
        $this->assertSame('application/xml', $importacao->arquivo_mime);
        $this->assertNotNull($importacao->arquivo_salvo_at);
        Storage::disk('local')->assertExists($importacao->arquivo_path);
        $this->assertSame($conteudo, Storage::disk('local')->get($importacao->arquivo_path));
    }

    public function test_falha_do_parser_preserva_xml_e_marca_importacao_como_erro(): void
    {
        Storage::fake('local');
        $usuario = $this->usuario('xml-falha@example.com');
        $conteudo = '<LISTING><NUMERO_PEDIDO>123</NUMERO_PEDIDO></LISTING>';

        $this->mock(FornecedorPedidoXmlParserService::class, function ($mock): void {
            $mock->shouldReceive('extrair')->once()->andThrow(new \RuntimeException('XML inválido no fornecedor'));
        });

        $response = $this->actingAs($usuario, 'sanctum')->post('/api/v1/pedidos/import', [
            'tipo_importacao' => 'PRODUTOS_XML_FORNECEDORES',
            'arquivo' => UploadedFile::fake()->createWithContent('falha.xml', $conteudo),
        ]);

        $response->assertStatus(500);
        $importacao = PedidoImportacao::query()->latest('id')->firstOrFail();
        $this->assertSame('erro', $importacao->status);
        $this->assertSame('XML inválido no fornecedor', $importacao->erro);
        Storage::disk('local')->assertExists($importacao->arquivo_path);
        $this->assertSame($conteudo, Storage::disk('local')->get($importacao->arquivo_path));
    }

    public function test_reprocessar_o_mesmo_xml_cria_caminhos_independentes(): void
    {
        Storage::fake('local');
        $usuario = $this->usuario('xml-reprocessamento@example.com');
        $conteudo = '<LISTING><NUMERO_PEDIDO>321</NUMERO_PEDIDO><TOTAL_LIQUIDO>20,00</TOTAL_LIQUIDO></LISTING>';

        $this->mock(FornecedorPedidoXmlParserService::class, function ($mock): void {
            $mock->shouldReceive('extrair')->twice()->andReturn([
                'pedido' => ['numero_pedido' => '321', 'cliente' => 'Cliente'],
                'itens' => [],
                'totais' => ['total_liquido' => '20,00'],
            ]);
        });

        foreach (['primeiro.xml', 'segundo.xml'] as $nome) {
            $this->actingAs($usuario, 'sanctum')->post('/api/v1/pedidos/import', [
                'tipo_importacao' => 'PRODUTOS_XML_FORNECEDORES',
                'arquivo' => UploadedFile::fake()->createWithContent($nome, $conteudo),
            ])->assertOk();
        }

        $importacoes = PedidoImportacao::query()->latest('id')->limit(2)->get();
        $this->assertCount(2, $importacoes);
        $this->assertNotSame($importacoes[0]->arquivo_path, $importacoes[1]->arquivo_path);
        $this->assertSame($importacoes[0]->arquivo_hash_conteudo, $importacoes[1]->arquivo_hash_conteudo);
        Storage::disk('local')->assertExists($importacoes[0]->arquivo_path);
        Storage::disk('local')->assertExists($importacoes[1]->arquivo_path);
    }

    public function test_upload_nfe_tambem_preserva_o_xml_original(): void
    {
        Storage::fake('local');
        $usuario = $this->usuario('xml-nfe-original@example.com');
        $fixture = base_path('tests/Fixtures/Importacao/Xml/35250207266606000112550020000450551000623840-nfe.xml');
        $conteudo = file_get_contents($fixture);

        $response = $this->actingAs($usuario, 'sanctum')->post('/api/v1/pedidos/import', [
            'tipo_importacao' => 'ADORNOS_XML_NFE',
            'arquivo' => new UploadedFile($fixture, 'nfe-original.xml', 'application/xml', null, true),
        ]);

        $response->assertOk();
        $importacao = PedidoImportacao::query()->latest('id')->firstOrFail();
        $this->assertSame(hash('sha256', $conteudo), $importacao->arquivo_hash_conteudo);
        Storage::disk('local')->assertExists($importacao->arquivo_path);
        $this->assertSame($conteudo, Storage::disk('local')->get($importacao->arquivo_path));
    }

    public function test_download_exige_permissao_e_entrega_o_xml_original(): void
    {
        Storage::fake('local');
        $usuario = $this->usuario('xml-download@example.com');
        $path = 'pedido-importacoes/77/original.xml';
        $conteudo = '<LISTING />';
        Storage::disk('local')->put($path, $conteudo);
        $importacao = PedidoImportacao::create([
            'arquivo_nome' => 'pedido original.xml',
            'arquivo_hash' => hash('sha256', uniqid('', true)),
            'arquivo_path' => $path,
            'arquivo_hash_conteudo' => hash('sha256', $conteudo),
            'arquivo_tamanho' => strlen($conteudo),
            'arquivo_mime' => 'application/xml',
            'arquivo_salvo_at' => now(),
            'usuario_id' => $usuario->id,
            'status' => 'extraido',
        ]);

        $response = $this->actingAs($usuario, 'sanctum')->get("/api/v1/pedidos/importacoes/{$importacao->id}/xml");
        $response->assertForbidden();

        $this->permitirDownload($usuario->id);
        $response = $this->actingAs($usuario, 'sanctum')->get("/api/v1/pedidos/importacoes/{$importacao->id}/xml");
        $response->assertOk();
        $this->assertSame($conteudo, $response->streamedContent());
        $this->assertStringContainsString('pedido original.xml', (string) $response->headers->get('content-disposition'));
    }
}
