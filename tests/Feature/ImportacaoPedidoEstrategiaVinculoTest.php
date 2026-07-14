<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Fornecedor;
use App\Models\Pedido;
use App\Models\PedidoImportacaoItem;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
use App\Models\ProdutoVariacaoCodigoHistorico;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportacaoPedidoEstrategiaVinculoTest extends TestCase
{
    use RefreshDatabase;

    public function test_forcar_produto_novo_cria_nova_variacao_mesmo_com_referencia_existente(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Estrategia',
            'email' => 'usuario_estrategia_'.Str::random(6).'@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create(['nome' => 'Cat Estrategia']);
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor Estrategia', 'status' => 1]);
        $produto = Produto::create([
            'nome' => 'Poltrona Teste',
            'id_categoria' => $categoria->id,
            'id_fornecedor' => $fornecedor->id,
            'ativo' => true,
        ]);

        $variacaoExistente = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-DUP-EST',
            'nome' => 'Var existente',
            'preco' => 100,
            'custo' => 50,
        ]);

        $numeroExterno = 'IMP-EST-'.Str::random(8);

        $payload = [
            'importacao_id' => null,
            'idempotency_key' => 'estrategia-forcar-produto-novo',
            'estrategia_vinculo' => 'REF_SELECAO',
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => $numeroExterno,
                'id_fornecedor' => $fornecedor->id,
                'total' => 80,
                'data_pedido' => '2024-01-10',
            ],
            'movimentar_estoque' => false,
            'itens' => [
                [
                    'ref' => 'REF-DUP-EST',
                    'codigo_origem' => 'REF-DUP-EST[ORIGEM-001]',
                    'nome' => $produto->nome,
                    'nome_detectado' => 'Poltrona Teste Detectada',
                    'quantidade' => 1,
                    'valor' => 80,
                    'preco_unitario' => 80,
                    'custo_unitario' => 40,
                    'id_categoria' => $categoria->id,
                    'forcar_produto_novo' => true,
                    'atributos_detectados' => ['madeira' => 'AC03'],
                    'vinculo_sugerido' => ['decisao' => 'nova'],
                    'variacoes_encontradas' => [['id_variacao' => $variacaoExistente->id]],
                    'compatibilidade' => ['percentual' => 0],
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertStatus(200);

        $this->assertSame(2, ProdutoVariacao::where('referencia', 'REF-DUP-EST')->count());

        $pedido = Pedido::where('numero_externo', $numeroExterno)->first();
        $this->assertNotNull($pedido);
        $item = $pedido->itens()->first();
        $this->assertNotNull($item);
        $this->assertNotSame($variacaoExistente->id, $item->id_variacao);
        $this->assertEqualsCanonicalizing(
            ['REF-DUP-EST', 'REF-DUP-EST[ORIGEM-001]'],
            ProdutoVariacaoCodigoHistorico::query()
                ->where('produto_variacao_id', $item->id_variacao)
                ->pluck('codigo_origem')
                ->all()
        );

        $auditoria = PedidoImportacaoItem::query()->where('pedido_item_id', $item->id)->firstOrFail();
        $this->assertArrayNotHasKey('vinculo_sugerido', $auditoria->dados_importados_json);
        $this->assertArrayNotHasKey('variacoes_encontradas', $auditoria->dados_importados_json);
        $this->assertArrayNotHasKey('compatibilidade', $auditoria->dados_importados_json);
        $this->assertArrayNotHasKey('atributos_detectados', $auditoria->dados_importados_json);
        $this->assertArrayNotHasKey('nome_detectado', $auditoria->dados_importados_json);
        $this->assertSame('REF-DUP-EST[ORIGEM-001]', $auditoria->dados_importados_json['codigo_origem']);
    }

    public function test_referencia_unica_sem_flag_vincula_variacao_existente(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Vinculo Unico',
            'email' => 'usuario_unico_'.Str::random(6).'@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create(['nome' => 'Cat Vinculo Unico']);
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor Vinculo Unico', 'status' => 1]);
        $produto = Produto::create([
            'nome' => 'Mesa Teste',
            'id_categoria' => $categoria->id,
            'id_fornecedor' => $fornecedor->id,
            'ativo' => true,
        ]);

        $variacaoExistente = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-UNICA-CONFIRM',
            'nome' => 'Var unica',
            'preco' => 200,
            'custo' => 100,
        ]);

        $numeroExterno = 'IMP-UNICA-'.Str::random(8);

        $payload = [
            'importacao_id' => null,
            'idempotency_key' => 'estrategia-vinculo-referencia-unica',
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => $numeroExterno,
                'id_fornecedor' => $fornecedor->id,
                'total' => 120,
                'data_pedido' => '2024-02-01',
            ],
            'movimentar_estoque' => false,
            'itens' => [
                [
                    'ref' => 'REF-UNICA-CONFIRM',
                    'nome' => $produto->nome,
                    'quantidade' => 1,
                    'valor' => 120,
                    'preco_unitario' => 120,
                    'custo_unitario' => 60,
                    'id_categoria' => $categoria->id,
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertStatus(200);

        $pedido = Pedido::where('numero_externo', $numeroExterno)->first();
        $item = $pedido?->itens()->first();

        $this->assertNotNull($item);
        $this->assertSame($variacaoExistente->id, $item->id_variacao);
    }

    public function test_atributo_com_valor_longo_retorna_validacao_amigavel_sem_sqlstate(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Atributo Longo',
            'email' => 'usuario_atributo_longo_'.Str::random(6).'@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create(['nome' => 'Cat Atributo Longo']);
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor Atributo Longo', 'status' => 1]);
        $numeroExterno = 'IMP-ATTR-'.Str::random(8);

        $payload = [
            'importacao_id' => null,
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => $numeroExterno,
                'id_fornecedor' => $fornecedor->id,
                'total' => 120,
                'data_pedido' => '2024-02-01',
            ],
            'movimentar_estoque' => false,
            'itens' => [
                [
                    'ref' => 'REF-ATTR-LONGO',
                    'nome' => 'Mesa Atributo Longo',
                    'quantidade' => 1,
                    'valor' => 120,
                    'preco_unitario' => 120,
                    'custo_unitario' => 60,
                    'id_categoria' => $categoria->id,
                    'forcar_produto_novo' => true,
                    'atributos' => [
                        'modelo_referencia' => str_repeat('A', 101),
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertStatus(422);

        $conteudo = $response->getContent();
        $this->assertStringContainsString('Produto 1: o valor do atributo', $conteudo);
        $this->assertStringNotContainsString('SQLSTATE', $conteudo);
    }

    public function test_confirmacao_preserva_atributos_com_mesmo_nome_e_valores_diferentes(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Atributos Repetidos',
            'email' => 'usuario_atributos_repetidos_'.Str::random(6).'@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);
        $categoria = Categoria::create(['nome' => 'Cat Atributos Repetidos']);
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor Atributos Repetidos', 'status' => 1]);
        $numeroExterno = 'IMP-ATTR-REP-'.Str::random(8);
        $atributosLista = [
            ['atributo' => 'madeira', 'valor' => 'AC03'],
            ['atributo' => 'madeira', 'valor' => 'MT31-PRETO'],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', [
                'importacao_id' => null,
                'idempotency_key' => 'atributos-repetidos-'.Str::uuid(),
                'cliente' => [],
                'pedido' => [
                    'tipo' => 'reposicao',
                    'numero_externo' => $numeroExterno,
                    'id_fornecedor' => $fornecedor->id,
                    'total' => 120,
                    'data_pedido' => '2024-02-01',
                ],
                'movimentar_estoque' => false,
                'itens' => [[
                    'ref' => 'REF-ATTR-REP',
                    'nome' => 'Mesa Atributos Repetidos',
                    'quantidade' => 1,
                    'valor' => 120,
                    'preco_unitario' => 120,
                    'custo_unitario' => 60,
                    'id_categoria' => $categoria->id,
                    'forcar_produto_novo' => true,
                    'atributos' => ['madeira' => 'MT31-PRETO'],
                    'atributos_lista' => $atributosLista,
                    'atributos_detectados' => ['madeira' => 'MT31-PRETO'],
                    'atributos_detectados_lista' => $atributosLista,
                ]],
            ]);

        $response->assertOk();

        $pedido = Pedido::query()->where('numero_externo', $numeroExterno)->firstOrFail();
        $variacaoId = $pedido->itens()->firstOrFail()->id_variacao;
        $this->assertSame(
            ['AC03', 'MT31-PRETO'],
            ProdutoVariacaoAtributo::query()
                ->where('id_variacao', $variacaoId)
                ->where('atributo', 'madeira')
                ->orderBy('valor')
                ->pluck('valor')
                ->all()
        );

        $auditoria = PedidoImportacaoItem::query()
            ->where('produto_variacao_id', $variacaoId)
            ->firstOrFail();
        $this->assertEquals($atributosLista, $auditoria->dados_importados_json['atributos_lista']);
        $this->assertArrayNotHasKey('atributos_detectados_lista', $auditoria->dados_importados_json);
    }

    public function test_confirmacao_rejeita_apenas_par_de_atributo_normalizado_repetido(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Par Repetido',
            'email' => 'usuario_par_repetido_'.Str::random(6).'@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);
        $categoria = Categoria::create(['nome' => 'Cat Par Repetido']);
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor Par Repetido', 'status' => 1]);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', [
                'importacao_id' => null,
                'idempotency_key' => 'par-repetido-'.Str::uuid(),
                'cliente' => [],
                'pedido' => [
                    'tipo' => 'reposicao',
                    'numero_externo' => 'IMP-PAR-REP-'.Str::random(8),
                    'id_fornecedor' => $fornecedor->id,
                    'total' => 120,
                    'data_pedido' => '2024-02-01',
                ],
                'movimentar_estoque' => false,
                'itens' => [[
                    'ref' => 'REF-PAR-REP',
                    'nome' => 'Mesa Par Repetido',
                    'quantidade' => 1,
                    'valor' => 120,
                    'preco_unitario' => 120,
                    'custo_unitario' => 60,
                    'id_categoria' => $categoria->id,
                    'forcar_produto_novo' => true,
                    'atributos_lista' => [
                        ['atributo' => 'Modelo Referência', 'valor' => 'AC-03'],
                        ['atributo' => 'modelo_referencia', 'valor' => 'ac 03'],
                    ],
                ]],
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['itens.0.atributos_lista.1.valor']);
        $erros = (array) $response->json('errors');
        $this->assertStringContainsString(
            'está duplicado',
            (string) ($erros['itens.0.atributos_lista.1.valor'][0] ?? '')
        );
    }

    public function test_forcar_produto_novo_sem_referencia_retorna_422_sem_criar_registros(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Novo Sem Ref',
            'email' => 'usuario_novo_sem_ref_'.Str::random(6).'@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create(['nome' => 'Cat Novo Sem Ref']);
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor Novo Sem Ref', 'status' => 1]);
        $pedidosAntes = Pedido::query()->count();
        $produtosAntes = Produto::query()->count();
        $variacoesAntes = ProdutoVariacao::query()->count();

        $payload = [
            'importacao_id' => null,
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => 'IMP-SEM-REF-'.Str::random(8),
                'id_fornecedor' => $fornecedor->id,
                'total' => 120,
                'data_pedido' => '2024-02-01',
            ],
            'movimentar_estoque' => false,
            'itens' => [
                [
                    'ref' => '',
                    'nome' => 'Mesa Sem Referencia',
                    'quantidade' => 1,
                    'valor' => 120,
                    'preco_unitario' => 120,
                    'custo_unitario' => 60,
                    'id_categoria' => $categoria->id,
                    'forcar_produto_novo' => true,
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('itens.0.ref');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());

        $this->assertSame($pedidosAntes, Pedido::query()->count());
        $this->assertSame($produtosAntes, Produto::query()->count());
        $this->assertSame($variacoesAntes, ProdutoVariacao::query()->count());
    }

    public function test_item_sem_vinculo_e_sem_referencia_retorna_422_sem_criar_registros(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Sem Vinculo Sem Ref',
            'email' => 'usuario_sem_vinculo_sem_ref_'.Str::random(6).'@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create(['nome' => 'Cat Sem Vinculo Sem Ref']);
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor Sem Vinculo Sem Ref', 'status' => 1]);
        $pedidosAntes = Pedido::query()->count();
        $produtosAntes = Produto::query()->count();
        $variacoesAntes = ProdutoVariacao::query()->count();

        $payload = [
            'importacao_id' => null,
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => 'IMP-SEM-VINC-'.Str::random(8),
                'id_fornecedor' => $fornecedor->id,
                'total' => 120,
                'data_pedido' => '2024-02-01',
            ],
            'movimentar_estoque' => false,
            'itens' => [
                [
                    'nome' => 'Mesa Sem Vinculo',
                    'quantidade' => 1,
                    'valor' => 120,
                    'preco_unitario' => 120,
                    'custo_unitario' => 60,
                    'id_categoria' => $categoria->id,
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('itens.0.ref');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());

        $this->assertSame($pedidosAntes, Pedido::query()->count());
        $this->assertSame($produtosAntes, Produto::query()->count());
        $this->assertSame($variacoesAntes, ProdutoVariacao::query()->count());
    }

    public function test_item_vinculado_por_variacao_nao_exige_referencia(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Vinculo Sem Ref',
            'email' => 'usuario_vinculo_sem_ref_'.Str::random(6).'@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create(['nome' => 'Cat Vinculo Sem Ref']);
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor Vinculo Sem Ref', 'status' => 1]);
        $produto = Produto::create([
            'nome' => 'Mesa Vinculada',
            'id_categoria' => $categoria->id,
            'id_fornecedor' => $fornecedor->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-VINCULADA',
            'nome' => 'Var vinculada',
            'preco' => 120,
            'custo' => 60,
        ]);
        $variacoesAntes = ProdutoVariacao::query()->count();

        $payload = [
            'importacao_id' => null,
            'idempotency_key' => 'estrategia-vinculo-variacao-sem-referencia',
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => 'IMP-VINC-SEM-REF-'.Str::random(8),
                'id_fornecedor' => $fornecedor->id,
                'total' => 120,
                'data_pedido' => '2024-02-01',
            ],
            'movimentar_estoque' => false,
            'itens' => [
                [
                    'id_variacao' => $variacao->id,
                    'ref' => '',
                    'nome' => 'Mesa Vinculada',
                    'quantidade' => 1,
                    'valor' => 120,
                    'preco_unitario' => 120,
                    'custo_unitario' => 60,
                    'id_categoria' => $categoria->id,
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertStatus(200);

        $pedido = Pedido::where('numero_externo', $payload['pedido']['numero_externo'])->first();
        $this->assertNotNull($pedido);
        $this->assertSame($variacao->id, $pedido->itens()->first()?->id_variacao);
        $this->assertSame($variacoesAntes, ProdutoVariacao::query()->count());
    }
}
