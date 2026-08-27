<?php

namespace Tests\Feature;

use App\Models\Carrinho;
use App\Models\CarrinhoItem;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinalizarPedidoSelecionandoVendedorTest extends TestCase
{
    use RefreshDatabase;

    public function test_finaliza_pedido_com_vendedor_selecionado(): void
    {
        $usuarioLogado = Usuario::create([
            'nome' => 'Usuario Logado',
            'email' => uniqid('finaliza-logado-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $vendedorSelecionado = Usuario::create([
            'nome' => 'Vendedor Selecionado',
            'email' => uniqid('finaliza-vendedor-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuarioLogado);
        Cache::put('permissoes_usuario_' . $usuarioLogado->id, [
            'pedidos.visualizar',
            'pedidos.selecionar_vendedor',
            'carrinhos.finalizar',
            'carrinhos.visualizar.todos',
        ]);

        $cliente = Cliente::create([
            'nome' => 'Cliente Finalizacao',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Finalizacao']);
        $produto = Produto::create([
            'nome' => 'Produto Finalizacao',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'FIN-001',
            'nome' => 'Var Finalizacao',
            'preco' => 120,
            'custo' => 80,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito Finalizacao']);
        Estoque::updateOrCreate([
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
        ], [
            'quantidade' => 5,
        ]);

        $carrinho = Carrinho::create([
            'id_usuario' => $usuarioLogado->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        CarrinhoItem::create([
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'quantidade' => 1,
            'id_deposito' => $deposito->id,
            'preco_unitario' => 120,
            'subtotal' => 120,
        ]);

        $payload = [
            'id_carrinho' => $carrinho->id,
            'id_cliente' => $cliente->id,
            'id_usuario' => $vendedorSelecionado->id,
            'observacoes' => 'Finalizacao com vendedor selecionado',
            'registrar_movimentacao' => false,
        ];

        $response = $this->postJson('/api/v1/pedidos', $payload);
        $response->assertStatus(201);

        $pedidoId = data_get($response->json(), 'pedido.id')
            ?? data_get($response->json(), 'data.id');

        $this->assertNotNull($pedidoId);

        $pedido = Pedido::findOrFail((int) $pedidoId);
        $this->assertSame((int) $vendedorSelecionado->id, (int) $pedido->id_usuario);
        $this->assertSame('pendente', $pedido->separacao_status);

        $carrinho->refresh();
        $this->assertSame('finalizado', $carrinho->status);
        $this->assertSame(0, DB::table('contas_receber')->where('pedido_id', $pedido->id)->count());
    }

    public function test_campo_legado_de_movimentacao_nao_bloqueia_pedido_sem_saldo(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Saldo',
            'email' => uniqid('finaliza-saldo-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, [
            'pedidos.visualizar',
            'carrinhos.finalizar',
            'carrinhos.visualizar.todos',
        ]);

        $cliente = Cliente::create([
            'nome' => 'Cliente Saldo',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Saldo']);
        $produto = Produto::create([
            'nome' => 'Mesa Sem Saldo',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'SEM-001',
            'nome' => 'Tampo Marmore',
            'preco' => 120,
            'custo' => 80,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito Sem Saldo']);
        Estoque::updateOrCreate([
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
        ], [
            'quantidade' => 0,
        ]);

        $carrinho = Carrinho::create([
            'id_usuario' => $usuario->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        $item = CarrinhoItem::create([
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => 1,
            'preco_unitario' => 120,
            'subtotal' => 120,
        ]);

        $response = $this->postJson('/api/v1/pedidos', [
            'id_carrinho' => $carrinho->id,
            'id_cliente' => $cliente->id,
            'registrar_movimentacao' => true,
            'depositos_por_item' => [
                [
                    'id_carrinho_item' => $item->id,
                    'id_deposito' => $deposito->id,
                ],
            ],
        ]);

        $response->assertCreated();

        $pedidoId = (int) data_get($response->json(), 'pedido.id');
        $this->assertGreaterThan(0, $pedidoId);
        $this->assertSame('finalizado', $carrinho->fresh()->status);
        $this->assertSame(0, (int) Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $deposito->id)
            ->value('quantidade'));
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedidoId)->count());
        $this->assertDatabaseHas('produto_entrega_itens', [
            'pedido_id' => $pedidoId,
            'id_variacao' => $variacao->id,
            'quantidade_total' => 1,
            'quantidade_reservada' => 0,
            'status' => 'aguardando_estoque',
        ]);
    }

    public function test_finaliza_pedido_com_valor_zero_sem_criar_conta_receber(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Valor Zero',
            'email' => uniqid('finaliza-zero-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, [
            'pedidos.visualizar',
            'carrinhos.finalizar',
            'carrinhos.visualizar.todos',
        ]);

        $cliente = Cliente::create([
            'nome' => 'Cliente Valor Zero',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Valor Zero']);
        $produto = Produto::create([
            'nome' => 'Produto Valor Zero',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'ZERO-001',
            'nome' => 'Variacao Zero',
            'preco' => 0,
            'custo' => 0,
        ]);

        $carrinho = Carrinho::create([
            'id_usuario' => $usuario->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        CarrinhoItem::create([
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'quantidade' => 1,
            'preco_unitario' => 0,
            'subtotal' => 0,
        ]);

        $response = $this->postJson('/api/v1/pedidos', [
            'id_carrinho' => $carrinho->id,
            'id_cliente' => $cliente->id,
            'registrar_movimentacao' => false,
        ]);

        $response->assertStatus(201);

        $pedidoId = data_get($response->json(), 'pedido.id')
            ?? data_get($response->json(), 'data.id');

        $this->assertNotNull($pedidoId);

        $pedido = Pedido::findOrFail((int) $pedidoId);
        $this->assertSame(0.0, (float) $pedido->valor_total);
        $this->assertSame('finalizado', $carrinho->fresh()->status);
        $this->assertSame(0, DB::table('contas_receber')->where('pedido_id', $pedido->id)->count());
    }
}
