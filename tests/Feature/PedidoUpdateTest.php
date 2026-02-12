<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Parceiro;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidoUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'pedido-update@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['pedidos.editar', 'pedidos.selecionar_vendedor']);

        $cliente = Cliente::create([
            'nome' => 'Cliente',
            'documento' => '12345678900',
        ]);

        $parceiro = Parceiro::create([
            'nome' => 'Parceiro',
            'tipo' => 'lojista',
            'documento' => '12345678000199',
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria']);
        $produto = Produto::create([
            'nome' => 'Produto Base',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacaoA = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-A',
            'nome' => 'Var A',
            'preco' => 100,
            'custo' => 60,
        ]);

        $variacaoB = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-B',
            'nome' => 'Var B',
            'preco' => 80,
            'custo' => 40,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito Teste']);

        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'id_parceiro' => $parceiro->id,
            'tipo' => 'venda',
            'numero_externo' => 'PED-001',
            'data_pedido' => now(),
            'valor_total' => 0,
            'observacoes' => 'Obs',
            'prazo_dias_uteis' => 10,
        ]);

        $item = PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacaoA->id,
            'id_deposito' => $deposito->id,
            'quantidade' => 1,
            'preco_unitario' => 100,
            'subtotal' => 100,
        ]);

        return [$pedido, $item, $variacaoA, $variacaoB, $deposito, $cliente, $parceiro];
    }

    public function test_atualiza_pedido_e_itens(): void
    {
        [$pedido, $item, $variacaoA, $variacaoB, $deposito, $cliente, $parceiro] = $this->seedBase();

        $payload = [
            'id_cliente' => $cliente->id,
            'id_parceiro' => $parceiro->id,
            'numero_externo' => 'PED-EDIT',
            'tipo' => 'venda',
            'data_pedido' => '2026-02-10',
            'prazo_dias_uteis' => 20,
            'observacoes' => 'Atualizado',
            'itens' => [
                [
                    'id' => $item->id,
                    'id_variacao' => $variacaoA->id,
                    'quantidade' => 2,
                    'preco_unitario' => 50,
                    'id_deposito' => $deposito->id,
                ],
                [
                    'id_variacao' => $variacaoB->id,
                    'quantidade' => 1,
                    'preco_unitario' => 80,
                    'id_deposito' => $deposito->id,
                ],
            ],
        ];

        $response = $this->putJson("/api/v1/pedidos/{$pedido->id}", $payload);
        $response->assertOk();

        $pedido->refresh();
        $this->assertSame('PED-EDIT', $pedido->numero_externo);
        $this->assertSame('Atualizado', $pedido->observacoes);
        $this->assertSame(180.0, (float) $pedido->valor_total);

        $this->assertDatabaseHas('pedido_itens', [
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacaoA->id,
            'quantidade' => 2,
        ]);

        $this->assertDatabaseHas('pedido_itens', [
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacaoB->id,
            'quantidade' => 1,
        ]);
    }

    public function test_valida_payload_invalido(): void
    {
        [$pedido] = $this->seedBase();

        $payload = [
            'itens' => [
                [
                    'quantidade' => 0,
                    'preco_unitario' => 10,
                ],
            ],
        ];

        $response = $this->putJson("/api/v1/pedidos/{$pedido->id}", $payload);
        $response->assertStatus(422);
    }
}
