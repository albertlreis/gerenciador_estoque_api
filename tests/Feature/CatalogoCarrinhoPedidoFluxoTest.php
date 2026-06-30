<?php

namespace Tests\Feature;

use App\Models\Carrinho;
use App\Models\CarrinhoItem;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Consignacao;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueReserva;
use App\Models\Fornecedor;
use App\Models\OutletMotivo;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoOutlet;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogoCarrinhoPedidoFluxoTest extends TestCase
{
    use RefreshDatabase;

    private function autenticar(array $permissoes = [], array $perfis = []): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Fluxo',
            'email' => uniqid('fluxo-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, $permissoes);
        Cache::put('perfis_usuario_' . $usuario->id, $perfis);

        return $usuario;
    }

    private function criarCliente(string $nome = 'Cliente Fluxo'): Cliente
    {
        return Cliente::create([
            'nome' => $nome,
            'tipo' => 'pf',
            'documento' => null,
            'email' => uniqid('cliente-', true) . '@test.com',
        ]);
    }

    private function criarProdutoComVariacao(array $produtoData = [], array $variacaoData = [], ?Deposito $deposito = null, int $quantidade = 0): array
    {
        $categoria = Categoria::create(['nome' => uniqid('Categoria ', false)]);
        $fornecedor = Fornecedor::create([
            'nome' => uniqid('Fornecedor ', false),
            'status' => 1,
        ]);

        $produto = Produto::create(array_merge([
            'nome' => 'Produto Catalogo',
            'descricao' => 'Descricao',
            'id_categoria' => $categoria->id,
            'id_fornecedor' => $fornecedor->id,
            'codigo_produto' => 'CAT-' . strtoupper(substr(uniqid(), -5)),
            'ativo' => true,
            'altura' => 10,
            'largura' => 20,
            'profundidade' => 30,
            'peso' => 5,
        ], $produtoData));

        $variacao = ProdutoVariacao::create(array_merge([
            'produto_id' => $produto->id,
            'referencia' => 'REF-' . strtoupper(substr(uniqid(), -4)),
            'sku_interno' => 'SKU-' . strtoupper(substr(uniqid(), -4)),
            'nome' => 'Variacao Catalogo',
            'preco' => 120,
            'custo' => 70,
        ], $variacaoData));

        if ($deposito) {
            Estoque::updateOrCreate(
                [
                    'id_variacao' => $variacao->id,
                    'id_deposito' => $deposito->id,
                ],
                [
                    'quantidade' => $quantidade,
                ]
            );
        }

        return compact('produto', 'variacao', 'categoria', 'fornecedor');
    }

    private function criarCarrinhoComItem(Usuario $usuario, Cliente $cliente, ProdutoVariacao $variacao, int $quantidade = 2, ?Deposito $deposito = null): array
    {
        $carrinho = Carrinho::create([
            'id_usuario' => $usuario->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        $item = CarrinhoItem::create([
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'quantidade' => $quantidade,
            'preco_unitario' => $variacao->preco,
            'subtotal' => $variacao->preco * $quantidade,
            'id_deposito' => $deposito?->id,
        ]);

        return compact('carrinho', 'item');
    }

    private function criarOutletParaVariacao(ProdutoVariacao $variacao, int $quantidade, ?int $quantidadeRestante = null): ProdutoVariacaoOutlet
    {
        $motivo = OutletMotivo::create([
            'slug' => uniqid('motivo-', false),
            'nome' => 'Motivo Teste',
            'ativo' => true,
        ]);

        return ProdutoVariacaoOutlet::create([
            'produto_variacao_id' => $variacao->id,
            'motivo_id' => $motivo->id,
            'quantidade' => $quantidade,
            'quantidade_restante' => $quantidadeRestante ?? $quantidade,
            'usuario_id' => null,
        ]);
    }

    public function test_cria_carrinho_com_cliente_existente_e_vendedor_selecionado_quando_permitido(): void
    {
        $this->autenticar(['pedidos.selecionar_vendedor', 'carrinhos.finalizar']);
        $vendedorSelecionado = Usuario::create([
            'nome' => 'Vendedor Selecionado',
            'email' => uniqid('vend-sel-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        $cliente = $this->criarCliente('Cliente Existente');

        $response = $this->postJson('/api/v1/carrinhos', [
            'id_cliente' => $cliente->id,
            'id_usuario' => $vendedorSelecionado->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.id_cliente', $cliente->id)
            ->assertJsonPath('data.id_usuario', $vendedorSelecionado->id);

        $this->assertDatabaseHas('carrinhos', [
            'id_cliente' => $cliente->id,
            'id_usuario' => $vendedorSelecionado->id,
            'status' => 'rascunho',
        ]);
    }

    public function test_fluxo_de_novo_cliente_seguido_de_novo_carrinho(): void
    {
        $this->autenticar(['clientes.criar', 'carrinhos.finalizar'], ['Vendedor']);

        $clienteResponse = $this->postJson('/api/v1/clientes', [
            'tipo' => 'pf',
            'nome' => 'Cliente Novo Fluxo',
            'documento' => null,
            'email' => 'cliente.novo.fluxo@test.com',
            'telefone' => '91999999999',
        ]);

        $clienteResponse->assertCreated();
        $clienteId = (int) $clienteResponse->json('id');

        $carrinhoResponse = $this->postJson('/api/v1/carrinhos', [
            'id_cliente' => $clienteId,
        ]);

        $carrinhoResponse->assertCreated()
            ->assertJsonPath('data.id_cliente', $clienteId);

        $this->assertDatabaseHas('clientes', [
            'id' => $clienteId,
            'nome' => 'Cliente Novo Fluxo',
        ]);
    }

    public function test_adiciona_item_no_carrinho_persistindo_quantidade_enviada(): void
    {
        $usuario = $this->autenticar(['carrinhos.finalizar'], ['Vendedor']);
        $cliente = $this->criarCliente();
        $deposito = Deposito::create(['nome' => 'Deposito Carrinho']);
        ['variacao' => $variacao] = $this->criarProdutoComVariacao([], [], $deposito, 12);

        $carrinho = Carrinho::create([
            'id_usuario' => $usuario->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        $response = $this->postJson("/api/v1/carrinhos/{$carrinho->id}/itens", [
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'quantidade' => 3,
            'preco_unitario' => 120,
        ]);

        $response->assertCreated()
            ->assertJsonPath('quantidade', 3);

        $this->assertDatabaseHas('carrinho_itens', [
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'quantidade' => 3,
            'subtotal' => 360,
        ]);
    }

    public function test_adiciona_item_outlet_valido_no_carrinho(): void
    {
        $usuario = $this->autenticar(['carrinhos.finalizar'], ['Vendedor']);
        $cliente = $this->criarCliente();
        $deposito = Deposito::create(['nome' => 'Deposito Outlet']);
        ['variacao' => $variacao] = $this->criarProdutoComVariacao([], [], $deposito, 12);
        $outlet = $this->criarOutletParaVariacao($variacao, 5, 4);

        $carrinho = Carrinho::create([
            'id_usuario' => $usuario->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        $response = $this->postJson("/api/v1/carrinhos/{$carrinho->id}/itens", [
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'quantidade' => 3,
            'preco_unitario' => 99.9,
            'outlet_id' => $outlet->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('quantidade', 3)
            ->assertJsonPath('outlet_id', $outlet->id);

        $this->assertDatabaseHas('carrinho_itens', [
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'outlet_id' => $outlet->id,
            'quantidade' => 3,
        ]);
    }

    public function test_rejeita_item_quando_outlet_nao_pertence_a_variacao(): void
    {
        $usuario = $this->autenticar(['carrinhos.finalizar'], ['Vendedor']);
        $cliente = $this->criarCliente();
        $deposito = Deposito::create(['nome' => 'Deposito Outlet Invalido']);
        ['variacao' => $variacao] = $this->criarProdutoComVariacao([], [], $deposito, 12);
        ['variacao' => $outraVariacao] = $this->criarProdutoComVariacao([], [], $deposito, 7);
        $outlet = $this->criarOutletParaVariacao($outraVariacao, 5, 4);

        $carrinho = Carrinho::create([
            'id_usuario' => $usuario->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        $response = $this->postJson("/api/v1/carrinhos/{$carrinho->id}/itens", [
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'quantidade' => 2,
            'preco_unitario' => 90,
            'outlet_id' => $outlet->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['outlet_id']);

        $this->assertSame(
            'O outlet selecionado nao pertence a variacao informada.',
            data_get($response->json(), 'errors.outlet_id.0')
        );
    }

    public function test_rejeita_item_outlet_quando_quantidade_excede_saldo_restante(): void
    {
        $usuario = $this->autenticar(['carrinhos.finalizar'], ['Vendedor']);
        $cliente = $this->criarCliente();
        $deposito = Deposito::create(['nome' => 'Deposito Saldo Outlet']);
        ['variacao' => $variacao] = $this->criarProdutoComVariacao([], [], $deposito, 12);
        $outlet = $this->criarOutletParaVariacao($variacao, 5, 1);

        $carrinho = Carrinho::create([
            'id_usuario' => $usuario->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        $response = $this->postJson("/api/v1/carrinhos/{$carrinho->id}/itens", [
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'quantidade' => 2,
            'preco_unitario' => 90,
            'outlet_id' => $outlet->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantidade']);

        $this->assertSame(
            'Quantidade indisponivel para este outlet. Saldo atual: 1.',
            data_get($response->json(), 'errors.quantidade.0')
        );
    }

    public function test_mesma_variacao_pode_ter_linha_normal_e_linha_outlet_no_mesmo_carrinho(): void
    {
        $usuario = $this->autenticar(['carrinhos.finalizar'], ['Vendedor']);
        $cliente = $this->criarCliente();
        $deposito = Deposito::create(['nome' => 'Deposito Linhas Distintas']);
        ['variacao' => $variacao] = $this->criarProdutoComVariacao([], [], $deposito, 12);
        $outlet = $this->criarOutletParaVariacao($variacao, 5, 4);

        $carrinho = Carrinho::create([
            'id_usuario' => $usuario->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        $this->postJson("/api/v1/carrinhos/{$carrinho->id}/itens", [
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'quantidade' => 2,
            'preco_unitario' => 120,
        ])->assertCreated();

        $this->postJson("/api/v1/carrinhos/{$carrinho->id}/itens", [
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'quantidade' => 1,
            'preco_unitario' => 99.9,
            'outlet_id' => $outlet->id,
        ])->assertCreated();

        $this->assertDatabaseCount('carrinho_itens', 2);
        $this->assertDatabaseHas('carrinho_itens', [
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'outlet_id' => null,
            'quantidade' => 2,
        ]);
        $this->assertDatabaseHas('carrinho_itens', [
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'outlet_id' => $outlet->id,
            'quantidade' => 1,
        ]);
    }

    public function test_remove_item_do_carrinho(): void
    {
        $usuario = $this->autenticar(['carrinhos.finalizar'], ['Vendedor']);
        $cliente = $this->criarCliente();
        ['variacao' => $variacao] = $this->criarProdutoComVariacao();
        ['item' => $item] = $this->criarCarrinhoComItem($usuario, $cliente, $variacao, 2);

        $response = $this->deleteJson("/api/v1/carrinhos/{$item->id_carrinho}/itens/{$item->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('carrinho_itens', ['id' => $item->id]);
    }

    public function test_atualiza_depositos_de_itens_do_carrinho_em_lote(): void
    {
        $usuario = $this->autenticar(['carrinhos.finalizar'], ['Vendedor']);
        $cliente = $this->criarCliente();
        $depositoA = Deposito::create(['nome' => 'Deposito Lote A']);
        $depositoB = Deposito::create(['nome' => 'Deposito Lote B']);
        ['variacao' => $variacaoA] = $this->criarProdutoComVariacao([], ['preco' => 100], $depositoA, 5);
        ['variacao' => $variacaoB] = $this->criarProdutoComVariacao([], ['preco' => 80], $depositoB, 7);
        ['carrinho' => $carrinho, 'item' => $itemA] = $this->criarCarrinhoComItem($usuario, $cliente, $variacaoA, 1);

        $itemB = CarrinhoItem::create([
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacaoB->id,
            'quantidade' => 2,
            'preco_unitario' => $variacaoB->preco,
            'subtotal' => $variacaoB->preco * 2,
        ]);

        $response = $this->patchJson("/api/v1/carrinhos/{$carrinho->id}/itens/depositos", [
            'itens' => [
                [
                    'id_carrinho_item' => $itemA->id,
                    'id_deposito' => $depositoA->id,
                ],
                [
                    'id_carrinho_item' => $itemB->id,
                    'id_deposito' => $depositoB->id,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('updated', 2);

        $this->assertDatabaseHas('carrinho_itens', [
            'id' => $itemA->id,
            'id_carrinho' => $carrinho->id,
            'id_deposito' => $depositoA->id,
        ]);
        $this->assertDatabaseHas('carrinho_itens', [
            'id' => $itemB->id,
            'id_carrinho' => $carrinho->id,
            'id_deposito' => $depositoB->id,
        ]);
    }

    public function test_rejeita_atualizacao_de_deposito_de_item_de_outro_carrinho(): void
    {
        $usuario = $this->autenticar(['carrinhos.finalizar'], ['Vendedor']);
        $cliente = $this->criarCliente();
        $deposito = Deposito::create(['nome' => 'Deposito Item Outro Carrinho']);
        ['variacao' => $variacao] = $this->criarProdutoComVariacao([], ['preco' => 110], $deposito, 4);
        ['carrinho' => $carrinho] = $this->criarCarrinhoComItem($usuario, $cliente, $variacao, 1);
        ['item' => $itemOutroCarrinho] = $this->criarCarrinhoComItem($usuario, $cliente, $variacao, 1);

        $response = $this->patchJson("/api/v1/carrinhos/{$carrinho->id}/itens/depositos", [
            'itens' => [
                [
                    'id_carrinho_item' => $itemOutroCarrinho->id,
                    'id_deposito' => $deposito->id,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['itens.0.id_carrinho_item']);

        $this->assertDatabaseHas('carrinho_itens', [
            'id' => $itemOutroCarrinho->id,
            'id_deposito' => null,
        ]);
    }

    public function test_endpoint_antigo_atualiza_deposito_do_item_do_carrinho(): void
    {
        $usuario = $this->autenticar(['carrinhos.finalizar'], ['Vendedor']);
        $cliente = $this->criarCliente();
        $deposito = Deposito::create(['nome' => 'Deposito Endpoint Antigo']);
        ['variacao' => $variacao] = $this->criarProdutoComVariacao([], ['preco' => 90], $deposito, 3);
        ['carrinho' => $carrinho, 'item' => $item] = $this->criarCarrinhoComItem($usuario, $cliente, $variacao, 1);

        $response = $this->patchJson("/api/v1/carrinhos/{$carrinho->id}/itens/atualizar-deposito", [
            'id_carrinho_item' => $item->id,
            'id_deposito' => $deposito->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('carrinho_itens', [
            'id' => $item->id,
            'id_carrinho' => $carrinho->id,
            'id_deposito' => $deposito->id,
        ]);
    }

    public function test_finaliza_carrinho_como_venda_criando_demanda_e_reserva(): void
    {
        $usuario = $this->autenticar(['carrinhos.finalizar'], ['Vendedor']);
        $cliente = $this->criarCliente();
        $deposito = Deposito::create(['nome' => 'Deposito Venda']);
        ['variacao' => $variacao] = $this->criarProdutoComVariacao([], ['preco' => 150], $deposito, 10);
        ['carrinho' => $carrinho, 'item' => $item] = $this->criarCarrinhoComItem($usuario, $cliente, $variacao, 2, $deposito);

        $response = $this->postJson('/api/v1/pedidos', [
            'id_carrinho' => $carrinho->id,
            'id_cliente' => $cliente->id,
            'observacoes' => 'Fluxo de venda',
            'registrar_movimentacao' => true,
            'depositos_por_item' => [
                [
                    'id_carrinho_item' => $item->id,
                    'id_deposito' => $deposito->id,
                ],
            ],
        ]);

        $response->assertStatus(201);

        $pedidoId = (int) (data_get($response->json(), 'pedido.id') ?? data_get($response->json(), 'data.id'));
        $this->assertGreaterThan(0, $pedidoId);

        $pedido = Pedido::findOrFail($pedidoId);
        $this->assertSame($usuario->id, $pedido->id_usuario);

        $carrinho->refresh();
        $this->assertSame('finalizado', $carrinho->status);

        $estoque = Estoque::where('id_variacao', $variacao->id)
            ->where('id_deposito', $deposito->id)
            ->firstOrFail();

        $this->assertSame(10, (int) $estoque->quantidade);
        $this->assertDatabaseHas('produto_entrega_itens', [
            'pedido_id' => $pedido->id,
            'id_variacao' => $variacao->id,
            'pedido_item_id' => $pedido->itens()->firstOrFail()->id,
            'quantidade_total' => 2,
            'quantidade_reservada' => 2,
            'status' => ProdutoEntregaItem::STATUS_RESERVADO,
        ]);
        $this->assertDatabaseMissing('estoque_movimentacoes', [
            'pedido_id' => $pedido->id,
            'id_variacao' => $variacao->id,
            'id_deposito_origem' => $deposito->id,
        ]);
        $this->assertDatabaseMissing('consignacoes', ['pedido_id' => $pedido->id]);
    }

    public function test_finaliza_carrinho_como_consignacao_criando_registros_e_reservas(): void
    {
        $usuario = $this->autenticar(['carrinhos.finalizar'], ['Vendedor']);
        $cliente = $this->criarCliente();
        $deposito = Deposito::create(['nome' => 'Deposito Consignacao']);
        ['variacao' => $variacao] = $this->criarProdutoComVariacao([], ['preco' => 200], $deposito, 6);
        ['carrinho' => $carrinho, 'item' => $item] = $this->criarCarrinhoComItem($usuario, $cliente, $variacao, 2, $deposito);

        $response = $this->postJson('/api/v1/pedidos', [
            'id_carrinho' => $carrinho->id,
            'id_cliente' => $cliente->id,
            'observacoes' => 'Fluxo consignado',
            'modo_consignacao' => true,
            'prazo_consignacao' => 15,
            'registrar_movimentacao' => false,
            'depositos_por_item' => [
                [
                    'id_carrinho_item' => $item->id,
                    'id_deposito' => $deposito->id,
                ],
            ],
        ]);

        $response->assertStatus(201);

        $pedidoId = (int) (data_get($response->json(), 'pedido.id') ?? data_get($response->json(), 'data.id'));
        $pedido = Pedido::findOrFail($pedidoId);

        $this->assertDatabaseHas('consignacoes', [
            'pedido_id' => $pedido->id,
            'produto_variacao_id' => $variacao->id,
            'deposito_id' => $deposito->id,
            'quantidade' => 2,
            'status' => 'pendente',
        ]);
        $this->assertDatabaseHas('estoque_reservas', [
            'pedido_id' => $pedido->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => 2,
            'status' => 'ativa',
        ]);
        $this->assertSame(6, (int) Estoque::where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertDatabaseMissing('estoque_movimentacoes', [
            'pedido_id' => $pedido->id,
            'id_variacao' => $variacao->id,
            'tipo' => 'consignacao_envio',
        ]);

        $carrinho->refresh();
        $this->assertSame('finalizado', $carrinho->status);
    }

    public function test_rejeita_consignacao_sem_deposito_antes_de_criar_pedido(): void
    {
        $usuario = $this->autenticar(['carrinhos.finalizar'], ['Vendedor']);
        $cliente = $this->criarCliente();
        $deposito = Deposito::create(['nome' => 'Deposito Consignacao Obrigatorio']);
        ['variacao' => $variacao] = $this->criarProdutoComVariacao([], ['preco' => 200], $deposito, 6);
        ['carrinho' => $carrinho, 'item' => $item] = $this->criarCarrinhoComItem($usuario, $cliente, $variacao, 2);

        $response = $this->postJson('/api/v1/pedidos', [
            'id_carrinho' => $carrinho->id,
            'id_cliente' => $cliente->id,
            'observacoes' => 'Fluxo consignado sem deposito',
            'modo_consignacao' => true,
            'prazo_consignacao' => 15,
            'registrar_movimentacao' => false,
            'depositos_por_item' => [
                [
                    'id_carrinho_item' => $item->id,
                    'id_deposito' => null,
                ],
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['depositos_por_item']);

        $this->assertStringContainsString(
            'Selecione o deposito de saida para todos os itens da consignacao.',
            (string) data_get($response->json(), 'errors.depositos_por_item.0')
        );
        $this->assertSame('rascunho', $carrinho->fresh()->status);
        $this->assertSame(0, Pedido::where('id_cliente', $cliente->id)->count());
        $this->assertSame(0, Consignacao::count());
    }

    public function test_finaliza_consignacao_com_registrar_movimentacao_true_apenas_reserva(): void
    {
        $usuario = $this->autenticar(['carrinhos.finalizar'], ['Vendedor']);
        $cliente = $this->criarCliente();
        $deposito = Deposito::create(['nome' => 'Deposito Consignacao Legado']);
        ['variacao' => $variacao] = $this->criarProdutoComVariacao([], ['preco' => 200], $deposito, 4);
        ['carrinho' => $carrinho, 'item' => $item] = $this->criarCarrinhoComItem($usuario, $cliente, $variacao, 3, $deposito);

        $response = $this->postJson('/api/v1/pedidos', [
            'id_carrinho' => $carrinho->id,
            'id_cliente' => $cliente->id,
            'modo_consignacao' => true,
            'prazo_consignacao' => 15,
            'registrar_movimentacao' => true,
            'depositos_por_item' => [
                [
                    'id_carrinho_item' => $item->id,
                    'id_deposito' => $deposito->id,
                ],
            ],
        ]);

        $response->assertStatus(201);

        $pedidoId = (int) (data_get($response->json(), 'pedido.id') ?? data_get($response->json(), 'data.id'));
        $pedido = Pedido::findOrFail($pedidoId);

        $this->assertDatabaseHas('consignacoes', [
            'pedido_id' => $pedido->id,
            'produto_variacao_id' => $variacao->id,
            'deposito_id' => $deposito->id,
            'quantidade' => 3,
            'status' => 'pendente',
        ]);
        $this->assertDatabaseHas('estoque_reservas', [
            'pedido_id' => $pedido->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => 3,
            'status' => 'ativa',
        ]);
        $this->assertSame(4, (int) Estoque::where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertDatabaseMissing('estoque_movimentacoes', [
            'pedido_id' => $pedido->id,
            'id_variacao' => $variacao->id,
            'tipo' => 'consignacao_envio',
        ]);
    }

    public function test_vendedor_sem_permissao_recebe_403_ao_editar_produto(): void
    {
        $this->autenticar([], ['Vendedor']);
        ['produto' => $produto, 'categoria' => $categoria, 'fornecedor' => $fornecedor] = $this->criarProdutoComVariacao();

        $response = $this->putJson("/api/v1/produtos/{$produto->id}", [
            'nome' => 'Produto Sem Permissao',
            'descricao' => 'Tentativa de edicao',
            'id_categoria' => $categoria->id,
            'id_fornecedor' => $fornecedor->id,
            'ativo' => true,
        ]);

        $response->assertForbidden();
    }
}
