<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\Produto;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportacaoPedidoMovimentacaoEstoqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_venda_entregue_com_movimentacao_baixa_estoque_e_marca_entregue(): void
    {
        [$usuario, $cliente, $categoria, $variacao, $deposito] = $this->criarContexto();

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 5]
        );

        $payload = $this->payloadImportacao(
            tipo: 'venda',
            clienteId: $cliente->id,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 2,
            entregue: true,
            movimentarEstoque: true,
        );
        unset($payload['movimentar_estoque']);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', $payload);

        $response->assertOk();

        $pedidoId = $response->json('id');
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedidoId)->firstOrFail();

        $this->assertSame(3, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(2, (int) $entrega->quantidade_reservada);
        $this->assertSame(2, (int) $entrega->quantidade_expedida);
        $this->assertSame(2, (int) $entrega->quantidade_entregue);
        $this->assertSame(ProdutoEntregaItem::STATUS_ENTREGUE, $entrega->status);
        $this->assertSame(1, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->count());
        $this->assertSame(1, ProdutoEntregaEvento::query()->where('produto_entrega_item_id', $entrega->id)->where('tipo_evento', ProdutoEntregaEvento::EXPEDIDO_CLIENTE)->count());
        $this->assertSame(1, ProdutoEntregaEvento::query()->where('produto_entrega_item_id', $entrega->id)->where('tipo_evento', ProdutoEntregaEvento::ENTREGUE_CLIENTE)->count());
    }

    public function test_venda_entregue_sem_movimentacao_cria_demanda_pendente_sem_baixa(): void
    {
        [$usuario, $cliente, $categoria, $variacao, $deposito] = $this->criarContexto();

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 5]
        );

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', $this->payloadImportacao(
                tipo: 'venda',
                clienteId: $cliente->id,
                categoriaId: $categoria->id,
                variacaoId: $variacao->id,
                depositoId: $deposito->id,
                quantidade: 2,
                entregue: true,
                movimentarEstoque: false,
            ));

        $response->assertOk();

        $pedidoId = $response->json('id');
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedidoId)->firstOrFail();

        $this->assertSame(5, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(0, (int) $entrega->quantidade_reservada);
        $this->assertSame(0, (int) $entrega->quantidade_expedida);
        $this->assertSame(0, (int) $entrega->quantidade_entregue);
        $this->assertSame(ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE, $entrega->status);
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->count());
        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedidoId,
            'status' => PedidoStatus::ENTREGA_CLIENTE->value,
        ]);
    }

    public function test_importacao_permite_numero_externo_repetido(): void
    {
        [$usuario, , $categoria, $variacao, $deposito] = $this->criarContexto();
        $numeroExterno = 'XML-DUP-001';

        $payloadA = $this->payloadImportacao(
            tipo: 'reposicao',
            clienteId: null,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 1,
            entregue: false,
            movimentarEstoque: false,
        );
        $payloadA['pedido']['numero_externo'] = $numeroExterno;

        $payloadB = $this->payloadImportacao(
            tipo: 'reposicao',
            clienteId: null,
            categoriaId: $categoria->id,
            variacaoId: $variacao->id,
            depositoId: $deposito->id,
            quantidade: 2,
            entregue: false,
            movimentarEstoque: false,
        );
        $payloadB['pedido']['numero_externo'] = $numeroExterno;

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', $payloadA)
            ->assertOk();

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', $payloadB)
            ->assertOk();

        $this->assertSame(2, \App\Models\Pedido::query()->where('numero_externo', $numeroExterno)->count());
    }

    public function test_reposicao_recebida_com_movimentacao_registra_entrada_no_deposito(): void
    {
        [$usuario, , $categoria, $variacao, $deposito] = $this->criarContexto();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', $this->payloadImportacao(
                tipo: 'reposicao',
                clienteId: null,
                categoriaId: $categoria->id,
                variacaoId: $variacao->id,
                depositoId: $deposito->id,
                quantidade: 4,
                entregue: true,
                movimentarEstoque: true,
            ));

        $response->assertOk();

        $pedidoId = $response->json('id');
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedidoId)->firstOrFail();

        $this->assertSame(4, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertNull($entrega->id_deposito_origem);
        $this->assertSame($deposito->id, (int) $entrega->id_deposito_destino);
        $this->assertSame(4, (int) $entrega->quantidade_recebida);
        $this->assertSame(ProdutoEntregaItem::STATUS_RECEBIDO, $entrega->status);
        $this->assertSame(1, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->count());
        $this->assertSame(1, ProdutoEntregaEvento::query()->where('produto_entrega_item_id', $entrega->id)->where('tipo_evento', ProdutoEntregaEvento::RECEBIDO_ESTOQUE)->count());
        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedidoId,
            'status' => PedidoStatus::ENTREGA_ESTOQUE->value,
        ]);
    }

    public function test_reposicao_recebida_sem_movimentacao_fica_em_recebiveis_sem_entrada(): void
    {
        [$usuario, , $categoria, $variacao, $deposito] = $this->criarContexto();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', $this->payloadImportacao(
                tipo: 'reposicao',
                clienteId: null,
                categoriaId: $categoria->id,
                variacaoId: $variacao->id,
                depositoId: $deposito->id,
                quantidade: 4,
                entregue: true,
                movimentarEstoque: false,
            ));

        $response->assertOk();

        $pedidoId = $response->json('id');
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedidoId)->firstOrFail();

        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame($deposito->id, (int) $entrega->id_deposito_destino);
        $this->assertSame(0, (int) $entrega->quantidade_recebida);
        $this->assertSame(ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE, $entrega->status);
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->count());
        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedidoId,
            'status' => PedidoStatus::ENTREGA_ESTOQUE->value,
        ]);

        $this->getJson('/api/v1/entregas/itens?recebiveis=1&per_page=10')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $entrega->id,
                'quantidade_pendente_recebimento' => 4,
            ]);
    }

    public function test_reposicao_recebida_sem_deposito_retorna_validacao(): void
    {
        [$usuario, , $categoria, $variacao] = $this->criarContexto();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', $this->payloadImportacao(
                tipo: 'reposicao',
                clienteId: null,
                categoriaId: $categoria->id,
                variacaoId: $variacao->id,
                depositoId: null,
                quantidade: 1,
                entregue: true,
                movimentarEstoque: true,
            ));

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('itens');
    }

    private function criarContexto(): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Importacao',
            'email' => uniqid('importacao-', false) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $cliente = Cliente::create([
            'nome' => 'Cliente Importacao',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Importacao']);

        $produto = Produto::create([
            'nome' => 'Produto Importacao',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => uniqid('IMP-', false),
            'nome' => 'Variacao Importacao',
            'preco' => 100,
            'custo' => 50,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito Importacao']);

        return [$usuario, $cliente, $categoria, $variacao, $deposito];
    }

    private function payloadImportacao(
        string $tipo,
        ?int $clienteId,
        int $categoriaId,
        int $variacaoId,
        ?int $depositoId,
        int $quantidade,
        bool $entregue,
        bool $movimentarEstoque
    ): array {
        $data = now()->toDateString();

        return [
            'tipo_importacao' => 'PRODUTOS_XML_FORNECEDORES',
            'cliente' => $clienteId ? ['id' => $clienteId] : [],
            'pedido' => [
                'tipo' => $tipo,
                'numero_externo' => uniqid('IMP-', false),
                'total' => $quantidade * 100,
                'data_pedido' => $data,
                'data_entrega' => $entregue ? $data : null,
                'entregue' => $entregue,
                'previsao_tipo' => 'DIAS_UTEIS',
                'dias_uteis_previstos' => 0,
            ],
            'entregue' => $entregue,
            'movimentar_estoque' => $movimentarEstoque,
            'data_entrega' => $entregue ? $data : null,
            'previsao_tipo' => 'DIAS_UTEIS',
            'dias_uteis_previstos' => 0,
            'itens' => [
                [
                    'nome' => 'Produto Importacao',
                    'ref' => 'REF-IMPORTACAO',
                    'quantidade' => $quantidade,
                    'valor' => 100,
                    'preco_unitario' => 100,
                    'custo_unitario' => 50,
                    'id_categoria' => $categoriaId,
                    'id_variacao' => $variacaoId,
                    'id_deposito' => $depositoId,
                    'atributos' => [],
                ],
            ],
        ];
    }
}
