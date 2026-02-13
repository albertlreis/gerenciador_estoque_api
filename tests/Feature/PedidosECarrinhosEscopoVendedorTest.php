<?php

namespace Tests\Feature;

use App\Models\Carrinho;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidosECarrinhosEscopoVendedorTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarComPermissoes(array $permissoes): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Escopo',
            'email' => uniqid('escopo-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, $permissoes);

        return $usuario;
    }

    public function test_vendedor_visualiza_pedidos_de_todos(): void
    {
        $this->autenticarComPermissoes(['pedidos.visualizar', 'carrinhos.finalizar']);

        $vendedorA = Usuario::create([
            'nome' => 'Vendedor A',
            'email' => uniqid('vend-a-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $vendedorB = Usuario::create([
            'nome' => 'Vendedor B',
            'email' => uniqid('vend-b-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $clienteA = Cliente::create([
            'nome' => 'Cliente A',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $clienteB = Cliente::create([
            'nome' => 'Cliente B',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $pedidoA = Pedido::create([
            'id_cliente' => $clienteA->id,
            'id_usuario' => $vendedorA->id,
            'tipo' => 'venda',
            'numero_externo' => 'PED-A-' . strtoupper(substr(uniqid(), -5)),
            'data_pedido' => now(),
            'valor_total' => 100,
            'prazo_dias_uteis' => 10,
        ]);

        $pedidoB = Pedido::create([
            'id_cliente' => $clienteB->id,
            'id_usuario' => $vendedorB->id,
            'tipo' => 'venda',
            'numero_externo' => 'PED-B-' . strtoupper(substr(uniqid(), -5)),
            'data_pedido' => now(),
            'valor_total' => 200,
            'prazo_dias_uteis' => 10,
        ]);

        $response = $this->getJson('/api/v1/pedidos?per_page=50');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $pedidoA->id, $ids);
        $this->assertContains((int) $pedidoB->id, $ids);
    }

    public function test_vendedor_visualiza_carrinhos_ativos_de_todos_com_vendedor_nome(): void
    {
        $this->autenticarComPermissoes(['pedidos.visualizar', 'carrinhos.finalizar']);

        $vendedorA = Usuario::create([
            'nome' => 'Vendedor Carrinho A',
            'email' => uniqid('cart-a-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $vendedorB = Usuario::create([
            'nome' => 'Vendedor Carrinho B',
            'email' => uniqid('cart-b-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $cliente = Cliente::create([
            'nome' => 'Cliente Carrinho',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        Carrinho::create([
            'id_usuario' => $vendedorA->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        Carrinho::create([
            'id_usuario' => $vendedorB->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        $response = $this->getJson('/api/v1/carrinhos');
        $response->assertOk();

        $itens = collect($response->json('data'));
        $this->assertCount(2, $itens);
        $this->assertTrue($itens->every(fn ($item) => !empty($item['vendedor_nome'])));
    }
}

