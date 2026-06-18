<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Fornecedor;
use App\Models\Pedido;
use App\Models\PedidoImportacao;
use App\Models\Produto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportacaoPedidoPreviewContratoTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuario(string $email = 'usuario@example.com'): Usuario
    {
        return Usuario::create([
            'nome' => 'Usuario Test',
            'email' => $email,
            'senha' => 'teste',
            'ativo' => 1,
        ]);
    }

    private function criarFornecedor(array $attributes = []): Fornecedor
    {
        return Fornecedor::create(array_merge([
            'nome' => 'Fornecedor Teste',
            'cnpj' => '12345678000199',
            'status' => 1,
        ], $attributes));
    }

    private function criarCategoria(array $attributes = []): Categoria
    {
        return Categoria::create(array_merge([
            'nome' => 'Categoria Teste',
        ], $attributes));
    }

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
        $response->assertJsonPath('dados.pedido.numero_externo', '');
        $this->assertNull(PedidoImportacao::query()->latest('id')->first()?->numero_externo);
    }

    public function test_preview_listing_sugere_fornecedor_por_nome_unico(): void
    {
        $usuario = $this->criarUsuario('preview-fornecedor@example.com');
        $fornecedor = $this->criarFornecedor([
            'nome' => 'Moveis Beta',
            'cnpj' => null,
        ]);

        $this->mock(\App\Services\FornecedorPedidoXmlParserService::class, function ($mock) {
            $mock->shouldReceive('extrair')
                ->once()
                ->andReturn([
                    'pedido' => [
                        'numero_pedido' => '12345',
                        'data_pedido' => null,
                        'data_inclusao' => null,
                        'cliente' => 'Cliente Teste',
                        'observacoes' => '',
                        'fornecedor_sugerido' => [
                            'nome' => 'Móveis Beta',
                            'cnpj' => null,
                        ],
                    ],
                    'itens' => [
                        [
                            'nome' => 'Produto XML',
                            'quantidade' => 1,
                            'valor' => 100,
                            'id_categoria' => 1,
                        ],
                    ],
                    'totais' => [
                        'total_bruto' => '100,00',
                        'total_liquido' => '100,00',
                    ],
                ]);
        });

        $arquivo = UploadedFile::fake()->createWithContent(
            'pedido.xml',
            '<?xml version="1.0" encoding="UTF-8"?><LISTING><NUMERO_PEDIDO>12345</NUMERO_PEDIDO></LISTING>'
        );

        $response = $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/pedidos/import', [
                'tipo_importacao' => 'PRODUTOS_XML_FORNECEDORES',
                'arquivo' => $arquivo,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('dados.pedido.id_fornecedor', $fornecedor->id);
        $response->assertJsonPath('dados.pedido.fornecedor_sugerido.nome', 'Móveis Beta');
    }

    public function test_confirmar_sem_itens_retorna_422(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Test',
            'email' => 'confirm@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);
        $fornecedor = $this->criarFornecedor();

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
                'pedido' => ['tipo' => 'reposicao', 'numero_externo' => '999', 'total' => 100, 'id_fornecedor' => $fornecedor->id],
                'cliente' => [],
                'itens' => [],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('itens');
    }

    public function test_confirmar_sem_numero_retorna_422(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Numero',
            'email' => 'confirm-numero@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);
        $fornecedor = $this->criarFornecedor();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', [
                'pedido' => ['tipo' => 'reposicao', 'numero_externo' => '', 'total' => 100, 'id_fornecedor' => $fornecedor->id],
                'cliente' => [],
                'itens' => [
                    [
                        'nome' => 'Produto Teste',
                        'quantidade' => 1,
                        'valor' => 100,
                        'id_categoria' => 1,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('pedido.numero_externo');
    }

    public function test_confirmar_sem_fornecedor_cria_pedido_e_produto_sem_fornecedor(): void
    {
        $usuario = $this->criarUsuario('confirm-sem-fornecedor@example.com');
        $categoria = $this->criarCategoria();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', [
                'pedido' => [
                    'tipo' => 'reposicao',
                    'numero_externo' => 'MAN-SEM-FORN',
                    'id_fornecedor' => null,
                    'total' => 100,
                ],
                'movimentar_estoque' => false,
                'cliente' => [],
                'itens' => [
                    [
                        'ref' => 'REF-SEM-FORN',
                        'nome' => 'Produto Teste',
                        'quantidade' => 1,
                        'valor' => 100,
                        'preco_unitario' => 100,
                        'custo_unitario' => 80,
                        'id_categoria' => $categoria->id,
                    ],
                ],
            ]);

        $response->assertStatus(200);

        $pedido = Pedido::findOrFail($response->json('id'));
        $this->assertSame(Pedido::TIPO_REPOSICAO, $pedido->tipo);
        $this->assertNull($pedido->id_fornecedor);

        $produto = Produto::query()
            ->where('nome', 'Produto Teste')
            ->where('id_categoria', $categoria->id)
            ->firstOrFail();
        $this->assertNull($produto->id_fornecedor);
    }

    public function test_confirmar_manual_reposicao_com_fornecedor_cria_pedido(): void
    {
        $usuario = $this->criarUsuario('confirm-manual@example.com');
        $fornecedor = $this->criarFornecedor([
            'nome' => 'Fornecedor Manual',
            'cnpj' => '11222333000144',
        ]);
        $categoria = $this->criarCategoria();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', [
                'importacao_id' => null,
                'tipo_importacao' => null,
                'movimentar_estoque' => false,
                'pedido' => [
                    'tipo' => 'reposicao',
                    'numero_externo' => 'MAN-001',
                    'id_fornecedor' => $fornecedor->id,
                    'total' => 100,
                ],
                'cliente' => [],
                'itens' => [
                    [
                        'ref' => 'REF-MANUAL',
                        'sku_interno' => 'SKU-MANUAL',
                        'nome' => 'Produto Manual',
                        'quantidade' => 1,
                        'valor' => 100,
                        'preco_unitario' => 100,
                        'custo_unitario' => 80,
                        'id_categoria' => $categoria->id,
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $pedido = Pedido::findOrFail($response->json('id'));
        $this->assertSame(Pedido::TIPO_REPOSICAO, $pedido->tipo);
        $this->assertSame($fornecedor->id, $pedido->id_fornecedor);
        $this->assertNull(PedidoImportacao::query()->where('pedido_id', $pedido->id)->first());
    }
}
