<?php

namespace Tests\Feature;

use App\Models\Carrinho;
use App\Models\CarrinhoItem;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        $carrinho = Carrinho::create([
            'id_usuario' => $usuarioLogado->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        CarrinhoItem::create([
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'quantidade' => 1,
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

        $carrinho->refresh();
        $this->assertSame('finalizado', $carrinho->status);
    }
}

