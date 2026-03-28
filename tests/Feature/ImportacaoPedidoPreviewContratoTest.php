<?php

namespace Tests\Feature;

use App\Models\PedidoImportacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportacaoPedidoPreviewContratoTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_com_itens_retorna_200_com_flags_itens_extraidos_true(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Test',
            'email' => 'preview@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $path = base_path('tests/Fixtures/nfe-35250207.xml');
        $this->assertFileExists($path);
        $file = new UploadedFile($path, 'nfe-35250207.xml', 'application/xml', null, true);

        $response = $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/pedidos/import', [
                'tipo_importacao' => 'ADORNOS_XML_NFE',
                'arquivo' => $file,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('sucesso', true);
        $response->assertJsonPath('dados.itens_extraidos', true);
        $response->assertJsonPath('dados.requer_insercao_manual', false);
        $response->assertJsonPath('dados.avisos', []);
        $this->assertGreaterThan(0, count($response->json('dados.itens') ?? []));
    }

    public function test_preview_sem_itens_mas_com_pedido_minimo_retorna_200_e_requer_insercao_manual(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Test',
            'email' => 'preview2@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $this->mock(\App\Services\FornecedorPedidoXmlParserService::class, function ($mock) {
            $mock->shouldReceive('extrair')
                ->once()
                ->andReturn([
                    'pedido' => [
                        'numero_pedido' => '12345',
                        'data_pedido' => null,
                        'data_inclusao' => null,
                        'cliente' => 'Fornecedor Teste',
                        'observacoes' => '',
                    ],
                    'itens' => [],
                    'totais' => [
                        'total_bruto' => '1000,00',
                        'total_liquido' => '1000,00',
                    ],
                ]);
        });

        $arquivo = UploadedFile::fake()->createWithContent(
            'pedido.xml',
            '<?xml version="1.0" encoding="UTF-8"?><LISTING><NUMERO_PEDIDO>12345</NUMERO_PEDIDO><ITEMS /></LISTING>'
        );

        $response = $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/pedidos/import', [
                'tipo_importacao' => 'PRODUTOS_XML_FORNECEDORES',
                'arquivo' => $arquivo,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('sucesso', true);
        $response->assertJsonPath('dados.itens_extraidos', false);
        $response->assertJsonPath('dados.requer_insercao_manual', true);
        $this->assertNotEmpty($response->json('dados.avisos'));
        $response->assertJsonPath('dados.itens', []);
        $response->assertJsonPath('dados.pedido.numero_externo', '12345');
    }

    public function test_confirmar_sem_itens_retorna_422(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Test',
            'email' => 'confirm@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $importacao = PedidoImportacao::create([
            'arquivo_nome' => 'test.xml',
            'arquivo_hash' => hash('sha256', 'test'),
            'usuario_id' => $usuario->id,
            'status' => 'extraido',
            'dados_json' => [
                'tipo_importacao' => 'PRODUTOS_XML_FORNECEDORES',
                'pedido' => ['numero_externo' => '999', 'total' => 100],
                'itens' => [],
                'totais' => ['total_liquido' => '100'],
            ],
        ]);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', [
                'importacao_id' => $importacao->id,
                'pedido' => ['tipo' => 'reposicao', 'numero_externo' => '999', 'total' => 100],
                'cliente' => [],
                'itens' => [],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('itens');
    }
}
