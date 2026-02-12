<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidoDetalheCustoPermissaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarPedidoComItem(): Pedido
    {
        $vendedor = Usuario::create([
            'nome' => 'Vendedor Pedido',
            'email' => uniqid('vendedor-pedido-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $cliente = Cliente::create([
            'nome' => 'Cliente Teste',
            'documento' => '12345678901',
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Teste']);
        $produto = Produto::create([
            'nome' => 'Produto Teste',
            'descricao' => 'Descricao',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-001',
            'nome' => 'Variacao Teste',
            'preco' => 120,
            'custo' => 70,
        ]);

        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $vendedor->id,
            'tipo' => 'venda',
            'numero_externo' => 'PED-CUSTO-001',
            'data_pedido' => now(),
            'valor_total' => 240,
        ]);

        PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacao->id,
            'quantidade' => 2,
            'preco_unitario' => 120,
            'subtotal' => 240,
        ]);

        return $pedido;
    }

    private function autenticarComPermissoes(array $permissoes): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Permissao',
            'email' => uniqid('pedido-custo-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, $permissoes);

        return $usuario;
    }

    public function test_vendedor_nao_recebe_preco_custo_no_detalhe(): void
    {
        $pedido = $this->criarPedidoComItem();
        $this->autenticarComPermissoes(['pedidos.visualizar']);

        $response = $this->getJson("/api/v1/pedidos/{$pedido->id}/detalhado");

        $response->assertOk()
            ->assertJsonMissingPath('data.itens.0.preco_custo')
            ->assertJsonMissingPath('data.itens.0.total_custo')
            ->assertJsonPath('data.itens.0.preco_venda', 120);
    }

    public function test_admin_ou_estoque_recebe_preco_custo_no_detalhe(): void
    {
        $pedido = $this->criarPedidoComItem();
        $this->autenticarComPermissoes(['pedidos.visualizar', 'estoque.movimentacao']);

        $response = $this->getJson("/api/v1/pedidos/{$pedido->id}/detalhado");

        $response->assertOk()
            ->assertJsonPath('data.itens.0.preco_custo', 70)
            ->assertJsonPath('data.itens.0.total_custo', 140)
            ->assertJsonPath('data.itens.0.preco_venda', 120);
    }
}
