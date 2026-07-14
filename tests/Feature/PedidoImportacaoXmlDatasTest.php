<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\Fornecedor;
use App\Models\Pedido;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PedidoImportacaoXmlDatasTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirma_importacao_com_data_dd_mm_yyyy(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuário Teste',
            'email' => 'usuario_ddmm@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create([
            'nome' => 'Categoria Teste',
        ]);
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor Datas 1', 'status' => 1]);

        $numeroExterno = 'IMP-' . Str::random(8);

        $payload = [
            'importacao_id' => null,
            'idempotency_key' => 'importacao-data-dd-mm-yyyy',
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => $numeroExterno,
                'id_fornecedor' => $fornecedor->id,
                'total' => 100,
                'data_pedido' => '14/08/2020',
            ],
            'movimentar_estoque' => false,
            'itens' => [
                [
                    'ref' => 'REF-TESTE-1',
                    'nome' => 'Produto Teste',
                    'quantidade' => 1,
                    'valor' => 100,
                    'preco_unitario' => 100,
                    'id_categoria' => $categoria->id,
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertStatus(200);

        $pedido = Pedido::where('numero_externo', $numeroExterno)->first();
        $this->assertNotNull($pedido);
        $this->assertSame('2020-08-14', $pedido->data_pedido->toDateString());
    }

    public function test_confirma_importacao_com_data_dd_mm_yy_ponto(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuário Teste 2',
            'email' => 'usuario_ddmmyy@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create([
            'nome' => 'Categoria Teste 2',
        ]);
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor Datas 2', 'status' => 1]);

        $numeroExterno = 'IMP-' . Str::random(8);

        $payload = [
            'importacao_id' => null,
            'idempotency_key' => 'importacao-data-dd-mm-yy-ponto',
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => $numeroExterno,
                'id_fornecedor' => $fornecedor->id,
                'total' => 200,
                'data_pedido' => '14.08.20',
            ],
            'movimentar_estoque' => false,
            'itens' => [
                [
                    'ref' => 'REF-TESTE-2',
                    'nome' => 'Produto Teste 2',
                    'quantidade' => 2,
                    'valor' => 100,
                    'preco_unitario' => 100,
                    'id_categoria' => $categoria->id,
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertStatus(200);

        $pedido = Pedido::where('numero_externo', $numeroExterno)->first();
        $this->assertNotNull($pedido);
        $this->assertSame('2020-08-14', $pedido->data_pedido->toDateString());
    }

    public function test_confirma_importacao_calcula_data_limite_por_dias_uteis(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Prazo',
            'email' => 'usuario_prazo@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Prazo']);
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor Prazo', 'status' => 1]);
        $numeroExterno = 'IMP-' . Str::random(8);

        $payload = [
            'importacao_id' => null,
            'idempotency_key' => 'importacao-data-limite-dias-uteis',
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => $numeroExterno,
                'id_fornecedor' => $fornecedor->id,
                'total' => 100,
                'data_pedido' => '2025-01-03', // sexta-feira
            ],
            'previsao_tipo' => 'DIAS_UTEIS',
            'dias_uteis_previstos' => 1,
            'movimentar_estoque' => false,
            'itens' => [
                [
                    'ref' => 'REF-PRZ-1',
                    'nome' => 'Produto Prazo',
                    'quantidade' => 1,
                    'valor' => 100,
                    'preco_unitario' => 100,
                    'id_categoria' => $categoria->id,
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertStatus(200);

        $pedido = Pedido::where('numero_externo', $numeroExterno)->first();
        $this->assertNotNull($pedido);
        $this->assertSame('2025-01-06', optional($pedido->data_limite_entrega)->toDateString());
    }

    public function test_confirma_importacao_com_reposicao_legada_permanece_pendente_no_fluxo_v2(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Entrega',
            'email' => 'usuario_entrega@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Entrega']);
        $fornecedor = Fornecedor::create(['nome' => 'Fornecedor Entrega', 'status' => 1]);
        $deposito = Deposito::create(['nome' => 'Deposito Importacao Entregue']);
        $produto = Produto::create([
            'nome' => 'Produto Entregue',
            'id_categoria' => $categoria->id,
            'id_fornecedor' => $fornecedor->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-ENT-1',
            'nome' => 'Produto Entregue',
            'preco' => 100,
            'custo' => 100,
        ]);
        Estoque::updateOrCreate(
            [
                'id_variacao' => $variacao->id,
                'id_deposito' => $deposito->id,
            ],
            ['quantidade' => 10]
        );
        $numeroExterno = 'IMP-' . Str::random(8);

        $payload = [
            'importacao_id' => null,
            'idempotency_key' => 'importacao-reposicao-legada-fluxo-v2',
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => $numeroExterno,
                'id_fornecedor' => $fornecedor->id,
                'total' => 200,
                'data_pedido' => '2025-01-10',
            ],
            'entregue' => true,
            'data_entrega' => '2025-01-15',
            'movimentar_estoque' => true,
            'itens' => [
                [
                    'ref' => 'REF-ENT-1',
                    'nome' => 'Produto Entregue',
                    'quantidade' => 2,
                    'valor' => 100,
                    'preco_unitario' => 100,
                    'id_categoria' => $categoria->id,
                    'id_variacao' => $variacao->id,
                    'id_deposito' => $deposito->id,
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/xml/confirm', $payload);

        $response->assertStatus(200);

        $pedido = Pedido::where('numero_externo', $numeroExterno)->first();
        $this->assertNotNull($pedido);

        $statusCriado = PedidoStatusHistorico::query()
            ->where('pedido_id', $pedido->id)
            ->where('status', PedidoStatus::PEDIDO_CRIADO->value)
            ->first();

        $statusEntregue = PedidoStatusHistorico::query()
            ->where('pedido_id', $pedido->id)
            ->where('status', PedidoStatus::ENTREGA_ESTOQUE->value)
            ->first();

        $this->assertNotNull($statusCriado);
        $this->assertNull($statusEntregue);
        $this->assertSame(Pedido::ORIGEM_ABASTECIMENTO_FABRICA, $pedido->origem_abastecimento);
        $this->assertSame(0, (int) ProdutoEntregaItem::query()
            ->where('pedido_id', $pedido->id)
            ->value('quantidade_recebida'));
        $this->assertSame(10, (int) Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $deposito->id)
            ->value('quantidade'));
        $this->assertSame(0, EstoqueMovimentacao::query()
            ->where('pedido_id', $pedido->id)
            ->count());
    }
}
