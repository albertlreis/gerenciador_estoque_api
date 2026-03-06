<?php

namespace Tests\Feature;

use App\Models\Carrinho;
use App\Models\CarrinhoItem;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidoPrecoOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_bloqueia_override_de_preco_sem_permissao(): void
    {
        [$usuario, $cliente, $carrinho, $variacao, $deposito] = $this->criarCenario();

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['carrinhos.finalizar']);

        CarrinhoItem::create([
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'quantidade' => 1,
            'id_deposito' => $deposito->id,
            'preco_unitario' => 90,
            'subtotal' => 90,
        ]);

        $response = $this->postJson('/api/v1/pedidos', [
            'id_carrinho' => $carrinho->id,
            'id_cliente' => $cliente->id,
            'registrar_movimentacao' => false,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['itens.0.preco_unitario']);
    }

    public function test_persiste_preco_original_e_preco_editado_quando_usuario_tem_permissao(): void
    {
        [$usuario, $cliente, $carrinho, $variacao, $deposito] = $this->criarCenario('pedido-preco-com-permissao@test.com');

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, [
            'carrinhos.finalizar',
            'pedidos.editar',
        ]);

        CarrinhoItem::create([
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'quantidade' => 2,
            'id_deposito' => $deposito->id,
            'preco_unitario' => 95,
            'subtotal' => 190,
        ]);

        $response = $this->postJson('/api/v1/pedidos', [
            'id_carrinho' => $carrinho->id,
            'id_cliente' => $cliente->id,
            'registrar_movimentacao' => false,
        ]);

        $response->assertStatus(201);

        $pedidoId = data_get($response->json(), 'pedido.id');
        $pedido = Pedido::with('itens')->findOrFail($pedidoId);
        $item = $pedido->itens->firstOrFail();

        $this->assertSame(120.0, (float) $item->preco_original);
        $this->assertSame(95.0, (float) $item->preco_unitario);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'price_overridden',
            'auditable_type' => 'App\\Models\\PedidoItem',
            'auditable_id' => $item->id,
            'user_id' => $usuario->id,
        ]);
    }

    private function criarCenario(string $email = 'pedido-preco@test.com'): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Preco',
            'email' => $email,
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $cliente = Cliente::create([
            'nome' => 'Cliente Preco',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Preco']);
        $produto = Produto::create([
            'nome' => 'Produto Preco',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'PRECO-001',
            'nome' => 'Variacao Preco',
            'preco' => 120,
            'custo' => 70,
        ]);

        $carrinho = Carrinho::create([
            'id_usuario' => $usuario->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito Preco']);
        Estoque::updateOrCreate([
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
        ], [
            'quantidade' => 10,
        ]);

        return [$usuario, $cliente, $carrinho, $variacao, $deposito];
    }
}
