<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueReserva;
use App\Models\Pedido;
use App\Models\PedidoFabrica;
use App\Models\PedidoFabricaItem;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use App\Services\EntregaProdutoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoEntregaCentralTest extends TestCase
{
    use RefreshDatabase;

    public function test_pedido_reserva_expedicao_e_entrega_sem_baixa_duplicada(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(2);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 5]
        );

        $service = app(EntregaProdutoService::class);
        $service->criarDemandaPedido($pedido, $usuario->id, true);

        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->firstOrFail();
        $this->assertSame(2, (int) $entrega->quantidade_reservada);
        $this->assertSame(5, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count());

        $service->expedirPedido($pedido, $usuario->id);

        $entrega = $entrega->fresh();
        $this->assertSame(2, (int) $entrega->quantidade_expedida);
        $this->assertSame(3, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(1, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count());
        $this->assertSame('consumida', EstoqueReserva::query()->first()?->status);

        $service->entregarPedido($pedido, $usuario->id);

        $this->assertSame(3, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(1, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count());
        $this->assertSame(ProdutoEntregaItem::STATUS_ENTREGUE, $entrega->fresh()->status);
    }

    public function test_recebimento_de_fabrica_cria_entrada_e_evento_idempotente(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(3);

        $pedidoFabrica = PedidoFabrica::create(['status' => 'pendente']);
        $itemFabrica = PedidoFabricaItem::create([
            'pedido_fabrica_id' => $pedidoFabrica->id,
            'produto_variacao_id' => $variacao->id,
            'quantidade' => 3,
            'deposito_id' => $deposito->id,
            'pedido_venda_id' => $pedido->id,
        ]);

        $service = app(EntregaProdutoService::class);
        $central = $service->criarDemandaFabricaItem($itemFabrica, $usuario->id);
        $service->receberItem($central, $deposito->id, 3, $usuario->id, 'Recebimento teste', 'teste-fabrica-1');
        $service->receberItem($central, $deposito->id, 3, $usuario->id, 'Recebimento teste', 'teste-fabrica-1');

        $this->assertSame(3, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(1, EstoqueMovimentacao::query()->where('observacao', 'Recebimento teste')->count());
        $this->assertSame(1, ProdutoEntregaEvento::query()->where('idempotency_key', 'teste-fabrica-1')->count());
        $this->assertSame(ProdutoEntregaItem::STATUS_RECEBIDO, $central->fresh()->status);
    }

    public function test_expedicao_consumir_multiplas_reservas_do_mesmo_item(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(3);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 3]
        );

        $service = app(EntregaProdutoService::class);
        $central = $service->criarDemandaPedido($pedido, $usuario->id, false)->first();
        $service->reservarItem($central, $deposito->id, 1, $usuario->id, 'Reserva parcial A', 'reserva-parcial-a');
        $service->reservarItem($central, $deposito->id, 2, $usuario->id, 'Reserva parcial B', 'reserva-parcial-b');

        $service->expedirPedido($pedido, $usuario->id);

        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(3, (int) $central->fresh()->quantidade_expedida);
        $this->assertSame(0, EstoqueReserva::query()->where('pedido_id', $pedido->id)->where('status', 'ativa')->count());
        $this->assertSame(2, EstoqueReserva::query()->where('pedido_id', $pedido->id)->where('status', 'consumida')->count());
    }

    public function test_status_manual_nao_finaliza_sem_entrega_central(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(1);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['pedidos.editar']);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 1]
        );

        app(EntregaProdutoService::class)->criarDemandaPedido($pedido, $usuario->id, true);

        $response = $this->patchJson("/api/v1/pedidos/{$pedido->id}/status", [
            'status' => PedidoStatus::FINALIZADO->value,
            'data_prevista' => now()->toDateString(),
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'Registre a entrega pelo fluxo central antes de marcar entrega ou finalizacao.');

        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::FINALIZADO->value,
        ]);
    }

    private function criarPedidoComItem(int $quantidade): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Entrega',
            'email' => uniqid('entrega-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $cliente = Cliente::create([
            'nome' => 'Cliente Entrega',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);
        $categoria = Categoria::create(['nome' => 'Categoria Entrega']);
        $produto = Produto::create([
            'nome' => 'Produto Entrega',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => uniqid('ENT-', false),
            'nome' => 'Variacao Entrega',
            'preco' => 100,
            'custo' => 50,
        ]);
        $deposito = Deposito::create(['nome' => 'Deposito Entrega']);
        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => $quantidade * 100,
        ]);
        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::PEDIDO_CRIADO,
            'data_status' => now(),
            'usuario_id' => $usuario->id,
        ]);
        PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => $quantidade,
            'preco_unitario' => 100,
            'subtotal' => $quantidade * 100,
        ]);

        return [$usuario, $pedido->fresh('itens'), $variacao, $deposito];
    }
}
