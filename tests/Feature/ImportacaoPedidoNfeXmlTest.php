<?php

namespace Tests\Feature;

use App\Models\PedidoImportacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportacaoPedidoNfeXmlTest extends TestCase
{
    use RefreshDatabase;

    private function fixturePath(string $name): string
    {
        return base_path("tests/Fixtures/{$name}");
    }

    public function test_importa_nfe_xml_retorna_200_e_itens_nao_vazio(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario NFe',
            'email' => 'nfe@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $path = $this->fixturePath('nfe-35250207.xml');
        $this->assertFileExists($path);
        $file = new UploadedFile($path, 'nfe-35250207.xml', 'application/xml', null, true);

        $response = $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/pedidos/import', [
                'tipo_importacao' => 'ADORNOS_XML_NFE',
                'arquivo' => $file,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('sucesso', true);
        $response->assertJsonPath('dados.pedido.numero_externo', '45055');
        $response->assertJsonPath('dados.itens_extraidos', true);
        $response->assertJsonPath('dados.requer_insercao_manual', false);
        $this->assertGreaterThan(0, count($response->json('dados.itens') ?? []));
    }

    public function test_importa_segundo_nfe_xml_retorna_200_e_itens_nao_vazio(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario NFe 2',
            'email' => 'nfe2@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $path = $this->fixturePath('nfe-35260201.xml');
        $this->assertFileExists($path);
        $file = new UploadedFile($path, 'nfe-35260201.xml', 'application/xml', null, true);

        $response = $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/pedidos/import', [
                'tipo_importacao' => 'ADORNOS_XML_NFE',
                'arquivo' => $file,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('sucesso', true);
        $this->assertGreaterThan(0, count($response->json('dados.itens') ?? []));
    }

    public function test_rejeita_arquivo_zone_identifier_com_422(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario NFe',
            'email' => 'nfe-rej@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $path = $this->fixturePath('nfe-35250207.xml');
        $file = new UploadedFile($path, 'arquivo.xml:Zone.Identifier', 'application/xml', null, true);

        $response = $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/pedidos/import', [
                'tipo_importacao' => 'ADORNOS_XML_NFE',
                'arquivo' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('mensagem', 'Arquivo de metadados (Zone.Identifier) não é aceito.');
    }

    public function test_retorna_409_quando_arquivo_xml_ja_foi_confirmado(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario NFe Duplicado',
            'email' => 'nfe-duplicado@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $path = $this->fixturePath('nfe-35250207.xml');
        $hashArquivo = hash_file('sha256', $path);
        $hash = hash('sha256', $hashArquivo . '|ADORNOS_XML_NFE');

        PedidoImportacao::create([
            'arquivo_nome' => 'nfe-35250207.xml',
            'arquivo_hash' => $hash,
            'usuario_id' => $usuario->id,
            'status' => 'confirmado',
            'pedido_id' => 123,
        ]);

        $file = new UploadedFile($path, 'nfe-35250207.xml', 'application/xml', null, true);
        $response = $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/pedidos/import', [
                'tipo_importacao' => 'ADORNOS_XML_NFE',
                'arquivo' => $file,
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('sucesso', false);
        $response->assertJsonPath('pedido_id', 123);
    }
}
