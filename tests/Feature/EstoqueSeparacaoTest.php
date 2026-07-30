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

class EstoqueSeparacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_finalizacao_cria_reserva_e_lista_fila_de_separacao(): void
    {
        [$usuario, $pedidoId, $deposito, $variacao] = $this->criarPedidoComReserva();

        $this->assertDatabaseHas('pedidos', [
            'id' => $pedidoId,
            'separacao_status' => 'pendente',
        ]);

        $this->assertDatabaseHas('estoque_reservas', [
            'pedido_id' => $pedidoId,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'status' => 'ativa',
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['estoque.movimentar']);

        $response = $this->getJson('/api/v1/estoque/separacoes?status=pendente');

        $response->assertOk()->assertJsonPath('data.0.id', $pedidoId);
    }

    public function test_marcar_separado_registra_usuario_e_auditoria(): void
    {
        [$usuario, $pedidoId] = $this->criarPedidoComReserva('separacao-auditoria@test.com');

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['estoque.movimentar']);

        $response = $this->postJson("/api/v1/estoque/separacoes/{$pedidoId}/marcar-separado");

        $response->assertOk();

        $this->assertDatabaseHas('pedidos', [
            'id' => $pedidoId,
            'separacao_status' => 'separado',
            'separado_por' => $usuario->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'separation_marked',
            'auditable_type' => Pedido::class,
            'auditable_id' => $pedidoId,
            'user_id' => $usuario->id,
        ]);
    }

    private function criarPedidoComReserva(string $email = 'separacao@test.com'): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Separacao',
            'email' => $email,
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, [
            'carrinhos.finalizar',
            'pedidos.visualizar',
            'estoque.movimentar',
        ]);

        $cliente = Cliente::create([
            'nome' => 'Cliente Separacao',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Separacao']);
        $produto = Produto::create([
            'nome' => 'Produto Separacao',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'SEP-001',
            'nome' => 'Variacao Separacao',
            'preco' => 130,
            'custo' => 80,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito Separacao']);
        Estoque::updateOrCreate([
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
        ], [
            'quantidade' => 6,
        ]);

        $carrinho = Carrinho::create([
            'id_usuario' => $usuario->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        CarrinhoItem::create([
            'id_carrinho' => $carrinho->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => 2,
            'preco_unitario' => 130,
            'subtotal' => 260,
        ]);

        $response = $this->postJson('/api/v1/pedidos', [
            'id_carrinho' => $carrinho->id,
            'id_cliente' => $cliente->id,
        ]);

        $response->assertStatus(201);

        return [$usuario, (int) data_get($response->json(), 'pedido.id'), $deposito, $variacao];
    }
}
