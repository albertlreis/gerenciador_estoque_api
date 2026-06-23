<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ClienteEndereco;
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
        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::FINALIZADO->value,
        ]);
    }

    public function test_recebimento_total_de_reposicao_finaliza_pedido(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(3, Pedido::TIPO_REPOSICAO);

        $service = app(EntregaProdutoService::class);
        $central = $service->criarDemandaPedido($pedido, $usuario->id, false)->firstOrFail();

        $service->receberItem($central, $deposito->id, 3, $usuario->id, 'Recebimento total reposicao', 'reposicao-total-1');

        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::FINALIZADO->value,
            'usuario_id' => $usuario->id,
            'observacoes' => 'Pedido finalizado automaticamente apos recebimento total dos produtos.',
        ]);
        $this->assertSame(
            PedidoStatus::FINALIZADO->value,
            $pedido->historicoStatus()->latest('data_status')->latest('id')->first()?->getRawOriginal('status')
        );
    }

    public function test_recebimento_parcial_de_reposicao_nao_finaliza_pedido(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(3, Pedido::TIPO_REPOSICAO);

        $service = app(EntregaProdutoService::class);
        $central = $service->criarDemandaPedido($pedido, $usuario->id, false)->firstOrFail();

        $service->receberItem($central, $deposito->id, 1, $usuario->id, 'Recebimento parcial reposicao', 'reposicao-parcial-1');

        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::FINALIZADO->value,
        ]);
    }

    public function test_reposicao_com_multiplos_itens_finaliza_apenas_no_ultimo_recebimento(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(1, Pedido::TIPO_REPOSICAO);
        PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => 2,
            'preco_unitario' => 100,
            'subtotal' => 200,
        ]);
        $pedido->unsetRelation('itens');

        $service = app(EntregaProdutoService::class);
        $centrais = $service->criarDemandaPedido($pedido, $usuario->id, false)->values();

        $service->receberItem($centrais[0], $deposito->id, 1, $usuario->id, 'Recebimento item A', 'reposicao-multi-a');

        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::FINALIZADO->value,
        ]);

        $service->receberItem($centrais[1], $deposito->id, 2, $usuario->id, 'Recebimento item B', 'reposicao-multi-b');

        $this->assertSame(1, PedidoStatusHistorico::query()
            ->where('pedido_id', $pedido->id)
            ->where('status', PedidoStatus::FINALIZADO->value)
            ->count());
    }

    public function test_recebimento_idempotente_de_reposicao_nao_duplica_status_finalizado(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(1, Pedido::TIPO_REPOSICAO);

        $service = app(EntregaProdutoService::class);
        $central = $service->criarDemandaPedido($pedido, $usuario->id, false)->firstOrFail();

        $service->receberItem($central, $deposito->id, 1, $usuario->id, 'Recebimento idempotente', 'reposicao-idempotente-1');
        $service->receberItem($central, $deposito->id, 1, $usuario->id, 'Recebimento idempotente', 'reposicao-idempotente-1');
        $service->receberItem($central, $deposito->id, 1, $usuario->id, 'Recebimento sem pendencia', 'reposicao-idempotente-2');

        $this->assertSame(1, PedidoStatusHistorico::query()
            ->where('pedido_id', $pedido->id)
            ->where('status', PedidoStatus::FINALIZADO->value)
            ->count());
    }

    public function test_reposicao_cancelada_nao_finaliza_ao_receber_todos_os_itens(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(1, Pedido::TIPO_REPOSICAO);
        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::CANCELADO,
            'data_status' => now()->addSecond(),
            'usuario_id' => $usuario->id,
        ]);

        $service = app(EntregaProdutoService::class);
        $central = $service->criarDemandaPedido($pedido, $usuario->id, false)->firstOrFail();

        $service->receberItem($central, $deposito->id, 1, $usuario->id, 'Recebimento reposicao cancelada', 'reposicao-cancelada-1');

        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::FINALIZADO->value,
        ]);
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
        $this->assertSame(ProdutoEntregaItem::STATUS_RESERVADO, $entrega->status);
        $this->assertSame(1, (int) $entrega->quantidade_entregue);
        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(1, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count());
        $this->assertSame(1, ProdutoEntregaEvento::query()->where('idempotency_key', 'entrega-parcial-1')->count());
        $resumo = $service->resumoPedido($pedido->fresh());
        $this->assertSame(ProdutoEntregaItem::STATUS_RESERVADO, $resumo['status']);
        $this->assertTrue($resumo['parcial']);

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

    public function test_nota_entrega_pdf_sem_registrar_aceita_pendente_sem_saldo_sem_movimentar_estoque(): void
    {
        [$usuario, $pedido] = $this->criarPedidoComItem(1);

        Sanctum::actingAs($usuario);

        $entrega = app(EntregaProdutoService::class)
            ->criarDemandaPedido($pedido, $usuario->id, false)
            ->firstOrFail();

        $response = $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", [
            'registrar_entrega' => false,
            'observacao' => 'PDF documental sem saldo',
            'itens' => [
                [
                    'produto_entrega_item_id' => $entrega->id,
                    'quantidade' => 1,
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));

        $entrega = $entrega->fresh();
        $this->assertSame(0, (int) $entrega->quantidade_expedida);
        $this->assertSame(0, (int) $entrega->quantidade_entregue);
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count());
        $this->assertSame(0, ProdutoEntregaEvento::query()
            ->where('produto_entrega_item_id', $entrega->id)
            ->whereIn('tipo_evento', [
                ProdutoEntregaEvento::EXPEDIDO_CLIENTE,
                ProdutoEntregaEvento::ENTREGUE_CLIENTE,
            ])
            ->count());
    }

    public function test_nota_entrega_registrar_sem_saldo_continua_bloqueado(): void
    {
        [$usuario, $pedido] = $this->criarPedidoComItem(1);

        Sanctum::actingAs($usuario);

        $entrega = app(EntregaProdutoService::class)
            ->criarDemandaPedido($pedido, $usuario->id, false)
            ->firstOrFail();

        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", [
            'registrar_entrega' => true,
            'idempotency_key' => 'nota-entrega-sem-saldo',
            'observacao' => 'Tentativa de registro sem saldo',
            'itens' => [
                [
                    'produto_entrega_item_id' => $entrega->id,
                    'quantidade' => 1,
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('itens');

        $entrega = $entrega->fresh();
        $this->assertSame(0, (int) $entrega->quantidade_expedida);
        $this->assertSame(0, (int) $entrega->quantidade_entregue);
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count());
    }

    public function test_nota_entrega_pdf_exige_endereco_quando_cliente_tem_multiplos_enderecos(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(1);

        Sanctum::actingAs($usuario);
        $enderecoEscolhido = $this->criarEnderecoCliente($pedido->cliente, [
            'cep' => '66001010',
            'endereco' => 'Rua Nota Principal',
            'numero' => '10',
            'bairro' => 'Bairro Nota',
            'cidade' => 'Belem',
            'estado' => 'PA',
        ], true);
        $this->criarEnderecoCliente($pedido->cliente, [
            'cep' => '66001111',
            'endereco' => 'Rua Nota Secundaria',
            'numero' => '11',
            'bairro' => 'Bairro Nota 2',
            'cidade' => 'Belem',
            'estado' => 'PA',
        ]);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 1]
        );

        $service = app(EntregaProdutoService::class);
        $service->criarDemandaPedido($pedido, $usuario->id, true);
        $service->expedirPedido($pedido, $usuario->id);
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->firstOrFail();

        $payload = [
            'registrar_entrega' => false,
            'itens' => [
                [
                    'produto_entrega_item_id' => $entrega->id,
                    'quantidade' => 1,
                ],
            ],
        ];

        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('cliente_endereco_id');

        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", $payload + [
            'cliente_endereco_id' => $enderecoEscolhido->id,
        ])->assertOk();
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
        $this->assertSame(ProdutoEntregaItem::STATUS_RESERVADO, $entrega->status);
        $this->assertSame(1, (int) $entrega->quantidade_entregue);
        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(1, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count());
        $this->assertSame(1, ProdutoEntregaEvento::query()
            ->where('idempotency_key', "nota-entrega:nota-entrega-teste-1:item:{$entrega->id}:entregar")
            ->where('tipo_evento', ProdutoEntregaEvento::ENTREGUE_CLIENTE)
            ->count());
    }

    public function test_nota_entrega_itens_retorna_produtos_pendentes_com_depositos_disponiveis(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(3);
        $depositoExtra = Deposito::create(['nome' => 'Deposito Extra Nota']);

        Sanctum::actingAs($usuario);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 2]
        );
        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $depositoExtra->id],
            ['quantidade' => 1]
        );

        app(EntregaProdutoService::class)->criarDemandaPedido($pedido, $usuario->id, false);
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->firstOrFail();

        $this->getJson("/api/v1/pedidos/{$pedido->id}/nota-entrega/itens")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $entrega->id,
                'quantidade_pendente_total' => 3,
                'quantidade_pendente_expedicao_nota' => 3,
            ])
            ->assertJsonFragment([
                'id' => $deposito->id,
                'quantidade_utilizavel' => 2,
            ])
            ->assertJsonFragment([
                'id' => $depositoExtra->id,
                'quantidade_utilizavel' => 1,
            ]);
    }

    public function test_nota_entrega_itens_cria_demanda_para_pedido_sem_fluxo_central(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(2);

        Sanctum::actingAs($usuario);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 2]
        );

        $this->assertSame(0, ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->count());

        $this->getJson("/api/v1/pedidos/{$pedido->id}/nota-entrega/itens")
            ->assertOk()
            ->assertJsonFragment([
                'pedido_id' => $pedido->id,
                'quantidade_pendente_total' => 2,
                'quantidade_pendente_expedicao_nota' => 2,
            ])
            ->assertJsonFragment([
                'id' => $deposito->id,
                'quantidade_utilizavel' => 2,
            ]);

        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->firstOrFail();
        $this->assertSame(2, (int) $entrega->quantidade_total);
        $this->assertSame(0, (int) $entrega->quantidade_reservada);
        $this->assertSame(0, EstoqueReserva::query()->where('pedido_id', $pedido->id)->count());
    }

    public function test_nota_entrega_itens_retorna_reimpressao_quando_pedido_ja_foi_entregue(): void
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
        $service->entregarPedido($pedido, $usuario->id);

        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->firstOrFail();

        $this->getJson("/api/v1/pedidos/{$pedido->id}/nota-entrega/itens")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $entrega->id,
                'modo_nota' => 'reimpressao',
                'pode_registrar_entrega' => false,
                'quantidade_reimpressao' => 2,
                'quantidade_pendente_total' => 0,
            ]);
    }

    public function test_nota_entrega_reimpressao_nao_cria_eventos_nem_movimenta_estoque(): void
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
        $service->entregarPedido($pedido, $usuario->id);

        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->firstOrFail();
        $eventosAntes = ProdutoEntregaEvento::query()->where('produto_entrega_item_id', $entrega->id)->count();
        $movimentacoesAntes = EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count();
        $estoqueAntes = (int) Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $deposito->id)
            ->value('quantidade');

        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", [
            'registrar_entrega' => false,
            'observacao' => 'Reimpressao da nota',
            'itens' => [
                [
                    'produto_entrega_item_id' => $entrega->id,
                    'quantidade' => 2,
                ],
            ],
        ])->assertOk();

        $this->assertSame($eventosAntes, ProdutoEntregaEvento::query()->where('produto_entrega_item_id', $entrega->id)->count());
        $this->assertSame($movimentacoesAntes, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count());
        $this->assertSame($estoqueAntes, (int) Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $deposito->id)
            ->value('quantidade'));
    }

    public function test_nota_entrega_reimpressao_bloqueia_registro_e_quantidade_acima_do_entregue(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(1);

        Sanctum::actingAs($usuario);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 1]
        );

        $service = app(EntregaProdutoService::class);
        $service->criarDemandaPedido($pedido, $usuario->id, true);
        $service->expedirPedido($pedido, $usuario->id);
        $service->entregarPedido($pedido, $usuario->id);

        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->firstOrFail();

        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", [
            'registrar_entrega' => true,
            'idempotency_key' => 'reimpressao-nao-registra',
            'itens' => [
                [
                    'produto_entrega_item_id' => $entrega->id,
                    'quantidade' => 1,
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('itens');

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
    }

    public function test_nota_entrega_registra_expedicao_dividida_por_depositos_e_entrega_idempotente(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(3);
        $depositoExtra = Deposito::create(['nome' => 'Deposito Extra Nota']);

        Sanctum::actingAs($usuario);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 2]
        );
        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $depositoExtra->id],
            ['quantidade' => 1]
        );

        app(EntregaProdutoService::class)->criarDemandaPedido($pedido, $usuario->id, false);
        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->firstOrFail();
        $payload = [
            'registrar_entrega' => true,
            'idempotency_key' => 'nota-entrega-split-depositos',
            'observacao' => 'Entrega dividida por depositos',
            'itens' => [
                [
                    'produto_entrega_item_id' => $entrega->id,
                    'entregar_expedido' => 0,
                    'alocacoes' => [
                        ['deposito_id' => $deposito->id, 'quantidade' => 2],
                        ['deposito_id' => $depositoExtra->id, 'quantidade' => 1],
                    ],
                ],
            ],
        ];

        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", $payload)->assertOk();
        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", $payload)->assertOk();

        $entrega = $entrega->fresh();
        $this->assertSame(ProdutoEntregaItem::STATUS_ENTREGUE, $entrega->status);
        $this->assertSame(3, (int) $entrega->quantidade_expedida);
        $this->assertSame(3, (int) $entrega->quantidade_entregue);
        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $depositoExtra->id)->value('quantidade'));
        $this->assertSame(2, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count());
        $this->assertSame(2, ProdutoEntregaEvento::query()
            ->where('produto_entrega_item_id', $entrega->id)
            ->where('tipo_evento', ProdutoEntregaEvento::EXPEDIDO_CLIENTE)
            ->count());
        $this->assertSame(2, ProdutoEntregaEvento::query()
            ->where('produto_entrega_item_id', $entrega->id)
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
            'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
            'em_revisao' => true,
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

    public function test_recriar_entregas_reconstroi_tabelas_centrais_sem_movimentar_estoque(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(2);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 2]
        );

        $service = app(EntregaProdutoService::class);
        $service->criarDemandaPedido($pedido, $usuario->id, true);
        $service->expedirPedido($pedido, $usuario->id);

        $this->artisan('entregas:recriar --dry-run')
            ->assertExitCode(0);

        $this->artisan('entregas:recriar --apply')
            ->assertExitCode(0);

        $entrega = ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->firstOrFail();

        $this->assertSame(ProdutoEntregaItem::STATUS_RESERVADO, $entrega->status);
        $this->assertSame(2, (int) $entrega->quantidade_reservada);
        $this->assertSame(2, (int) $entrega->quantidade_expedida);
        $this->assertSame(0, (int) $entrega->quantidade_entregue);
        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(1, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count());
        $this->assertGreaterThanOrEqual(3, ProdutoEntregaEvento::query()->where('produto_entrega_item_id', $entrega->id)->count());
    }

    public function test_reserva_nao_ultrapassa_saldo_disponivel(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoComItem(3);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 2]
        );

        $service = app(EntregaProdutoService::class);
        $entrega = $service->criarDemandaPedido($pedido, $usuario->id, false)->first();

        $service->reservarItem($entrega, $deposito->id, 2, $usuario->id, 'Reserva inicial', 'reserva-limite-1');
        $bloqueada = $service->reservarItem($entrega, $deposito->id, 1, $usuario->id, 'Reserva excedente', 'reserva-limite-2');

        $this->assertSame(2, (int) EstoqueReserva::query()->where('pedido_id', $pedido->id)->sum('quantidade'));
        $this->assertSame(2, (int) $bloqueada->quantidade_reservada);
        $this->assertSame(ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE, $bloqueada->status);
        $this->assertStringContainsString('Estoque insuficiente', (string) $bloqueada->bloqueio_motivo);
    }

    private function criarPedidoComItem(int $quantidade, string $tipo = Pedido::TIPO_VENDA): array
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
            'tipo' => $tipo,
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

    private function criarEnderecoCliente(Cliente $cliente, array $dados, bool $principal = false): ClienteEndereco
    {
        return ClienteEndereco::create($dados + [
            'cliente_id' => $cliente->id,
            'principal' => $principal,
            'fingerprint' => hash('sha256', 'cliente-endereco-' . $cliente->id . '-' . ($dados['endereco'] ?? '') . '-' . uniqid('', true)),
        ]);
    }
}
