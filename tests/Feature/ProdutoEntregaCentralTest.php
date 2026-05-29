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

    public function test_entrega_parcial_projeta_status_e_filtro_entregaveis_sem_nova_baixa(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(3);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['pedidos.editar']);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 3]
        );

        $service = app(EntregaProdutoService::class);
        $service->criarDemandaPedido($pedido, $usuario->id, true);
        $service->expedirPedido($pedido, $usuario->id);

        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->firstOrFail();
        $service->entregarItem($entrega, 1, $usuario->id, 'Entrega parcial teste', 'entrega-parcial-1');
        $service->entregarItem($entrega, 1, $usuario->id, 'Entrega parcial teste', 'entrega-parcial-1');

        $entrega = $entrega->fresh();
        $this->assertSame(ProdutoEntregaItem::STATUS_ENTREGUE_PARCIAL, $entrega->status);
        $this->assertSame(1, (int) $entrega->quantidade_entregue);
        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(1, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count());
        $this->assertSame(1, ProdutoEntregaEvento::query()->where('idempotency_key', 'entrega-parcial-1')->count());
        $this->assertSame(ProdutoEntregaItem::STATUS_ENTREGUE_PARCIAL, $service->resumoPedido($pedido->fresh())['status']);

        $this->getJson('/api/v1/entregas/itens?entregaveis=1&per_page=10')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $entrega->id,
                'quantidade_pendente_entrega' => 2,
            ]);

        $this->patchJson("/api/v1/pedidos/{$pedido->id}/status", [
            'status' => PedidoStatus::FINALIZADO->value,
            'data_prevista' => now()->toDateString(),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Registre a entrega pelo fluxo central antes de marcar entrega ou finalizacao.');

        $service->entregarItem($entrega, 2, $usuario->id, 'Entrega restante teste', 'entrega-parcial-restante');

        $this->getJson('/api/v1/entregas/itens?entregaveis=1&per_page=10')
            ->assertOk()
            ->assertJsonMissing(['id' => $entrega->id]);
    }

    public function test_nota_entrega_pdf_sem_registrar_nao_cria_evento_de_entrega(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(2);

        Sanctum::actingAs($usuario);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 2]
        );

        $service = app(EntregaProdutoService::class);
        $service->criarDemandaPedido($pedido, $usuario->id, true);
        $service->expedirPedido($pedido, $usuario->id);

        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->firstOrFail();

        $response = $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", [
            'registrar_entrega' => false,
            'observacao' => 'Nota sem registro operacional',
            'itens' => [
                [
                    'produto_entrega_item_id' => $entrega->id,
                    'quantidade' => 1,
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('nota-entrega-pedido-' . $pedido->id . '.pdf', (string) $response->headers->get('content-disposition'));
        $this->assertSame(0, (int) $entrega->fresh()->quantidade_entregue);
        $this->assertSame(0, ProdutoEntregaEvento::query()
            ->where('produto_entrega_item_id', $entrega->id)
            ->where('tipo_evento', ProdutoEntregaEvento::ENTREGUE_CLIENTE)
            ->count());
        $this->assertSame(1, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count());
    }

    public function test_nota_entrega_pdf_registra_entrega_sem_movimentar_estoque_e_respeita_idempotencia(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(3);

        Sanctum::actingAs($usuario);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 3]
        );

        $service = app(EntregaProdutoService::class);
        $service->criarDemandaPedido($pedido, $usuario->id, true);
        $service->expedirPedido($pedido, $usuario->id);

        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->firstOrFail();
        $payload = [
            'registrar_entrega' => true,
            'idempotency_key' => 'nota-entrega-teste-1',
            'observacao' => 'Entrega pela nota',
            'itens' => [
                [
                    'produto_entrega_item_id' => $entrega->id,
                    'quantidade' => 1,
                ],
            ],
        ];

        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", $payload)->assertOk();
        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", $payload)->assertOk();

        $entrega = $entrega->fresh();
        $this->assertSame(ProdutoEntregaItem::STATUS_ENTREGUE_PARCIAL, $entrega->status);
        $this->assertSame(1, (int) $entrega->quantidade_entregue);
        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(1, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count());
        $this->assertSame(1, ProdutoEntregaEvento::query()
            ->where('idempotency_key', "nota-entrega:nota-entrega-teste-1:item:{$entrega->id}:entregar")
            ->where('tipo_evento', ProdutoEntregaEvento::ENTREGUE_CLIENTE)
            ->count());
    }

    public function test_nota_entrega_pdf_bloqueia_quantidade_acima_do_pendente_e_item_de_outro_pedido(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(1);
        [, $outroPedido, $outraVariacao, $outroDeposito] = $this->criarPedidoComItem(1);

        Sanctum::actingAs($usuario);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 1]
        );
        Estoque::updateOrCreate(
            ['id_variacao' => $outraVariacao->id, 'id_deposito' => $outroDeposito->id],
            ['quantidade' => 1]
        );

        $service = app(EntregaProdutoService::class);
        $service->criarDemandaPedido($pedido, $usuario->id, true);
        $service->expedirPedido($pedido, $usuario->id);
        $service->criarDemandaPedido($outroPedido, $usuario->id, true);
        $service->expedirPedido($outroPedido, $usuario->id);

        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->firstOrFail();
        $entregaOutroPedido = ProdutoEntregaItem::query()->where('pedido_id', $outroPedido->id)->firstOrFail();

        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", [
            'registrar_entrega' => false,
            'itens' => [
                [
                    'produto_entrega_item_id' => $entrega->id,
                    'quantidade' => 2,
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('itens');

        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", [
            'registrar_entrega' => false,
            'itens' => [
                [
                    'produto_entrega_item_id' => $entregaOutroPedido->id,
                    'quantidade' => 1,
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('itens');
    }

    public function test_listagem_de_entregas_filtra_por_busca_deposito_bloqueio_e_previsao(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(2);

        Sanctum::actingAs($usuario);

        $service = app(EntregaProdutoService::class);
        $service->criarDemandaPedido($pedido, $usuario->id, false);

        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->firstOrFail();
        $entrega->update([
            'status' => ProdutoEntregaItem::STATUS_BLOQUEADO_REVISAO,
            'previsao_entrega' => now()->addDays(2)->toDateString(),
            'bloqueio_motivo' => 'Deposito pendente para teste',
        ]);

        $this->getJson('/api/v1/entregas/itens?per_page=10&q=Cliente%20Entrega')
            ->assertOk()
            ->assertJsonFragment(['id' => $entrega->id]);

        $this->getJson('/api/v1/entregas/itens?per_page=10&q=sem-resultado-para-este-item')
            ->assertOk()
            ->assertJsonMissing(['id' => $entrega->id]);

        $this->getJson('/api/v1/entregas/itens?per_page=10&deposito_id=' . $deposito->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $entrega->id]);

        $this->getJson('/api/v1/entregas/itens?per_page=10&bloqueados=1')
            ->assertOk()
            ->assertJsonFragment(['id' => $entrega->id]);

        $this->getJson('/api/v1/entregas/itens?per_page=10&previsao_inicio=' . now()->toDateString() . '&previsao_fim=' . now()->addDays(3)->toDateString())
            ->assertOk()
            ->assertJsonFragment(['id' => $entrega->id]);

        $this->getJson('/api/v1/entregas/itens?per_page=10&previsao_inicio=' . now()->addDays(10)->toDateString())
            ->assertOk()
            ->assertJsonMissing(['id' => $entrega->id]);
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
