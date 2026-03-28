<?php

namespace Tests\Feature;

use App\Models\PedidoImportacao;
use App\Models\Usuario;
use App\Services\FornecedorPedidoXmlParserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PedidoImportacaoPdfPreviewCacheTest extends TestCase
{
    use DatabaseTransactions;

    public function test_importacao_reprocessa_quando_ha_preview_antigo_sem_itens(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Preview',
            'email' => 'usuario_preview@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $arquivo = UploadedFile::fake()->createWithContent(
            'preview.xml',
            '<?xml version="1.0" encoding="UTF-8"?><LISTING><NUMERO_PEDIDO>REPROC-001</NUMERO_PEDIDO><ITEMS><ITEM DESCRIPTION="Produto" QUANTITY="1.00" PRICE="10.00"><REFERENCES><CODE REFERENCE="REF-001" /></REFERENCES></ITEM></ITEMS></LISTING>'
        );

        PedidoImportacao::create([
            'arquivo_nome' => 'preview.xml',
            'arquivo_hash' => hash('sha256', 'preview-antigo'),
            'usuario_id' => $usuario->id,
            'status' => 'extraido',
            'dados_json' => [
                'tipo_importacao' => 'PRODUTOS_XML_FORNECEDORES',
                'pedido' => ['numero_externo' => ''],
                'itens' => [],
            ],
        ]);

        $mock = $this->mock(FornecedorPedidoXmlParserService::class);
        $mock->shouldReceive('extrair')
            ->once()
            ->andReturn([
                'pedido' => [
                    'numero_pedido' => 'REPROC-001',
                    'data_pedido' => null,
                    'data_inclusao' => null,
                ],
                'itens' => [
                    [
                        'codigo' => 'REF-001',
                        'descricao' => 'Produto Reprocessado',
                        'quantidade' => '1.00',
                        'preco_unitario' => '10.00',
                        'preco' => '10.00',
                    ],
                ],
                'totais' => [
                    'total_liquido' => '10,00',
                ],
            ]);
        $this->app->instance(FornecedorPedidoXmlParserService::class, $mock);

        $response = $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/pedidos/import', [
                'tipo_importacao' => 'PRODUTOS_XML_FORNECEDORES',
                'arquivo' => $arquivo,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('mensagem', 'Arquivo processado com sucesso.');
        $response->assertJsonPath('dados.pedido.numero_externo', 'REPROC-001');
        $response->assertJsonCount(1, 'dados.itens');
    }
}
