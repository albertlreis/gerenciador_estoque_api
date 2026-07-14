<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\Pedido;
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
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidoFluxoOperacionalV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_recebimento_parcial_e_idempotente_reserva_venda_e_aplica_entrega_estoque_ao_concluir(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedido(2, Pedido::ORIGEM_ABASTECIMENTO_FABRICA);
        Sanctum::actingAs($usuario);

        $entrega = app(EntregaProdutoService::class)
            ->criarDemandaPedido($pedido, $usuario->id, false)
            ->firstOrFail();

        $this->patchJson("/api/v1/pedidos/{$pedido->id}/status", [
            'status' => PedidoStatus::ENTREGA_ESTOQUE->value,
        ])->assertStatus(409)
            ->assertJsonPath('code', 'RECEBIMENTO_ITENS_PENDENTE')
            ->assertJsonPath('itens.0.faltante', 2);

        $primeiro = [
            'itens' => [[
                'produto_entrega_item_id' => $entrega->id,
                'quantidade' => 1,
                'id_deposito_destino' => $deposito->id,
                'ocorrido_em' => '2026-07-09 10:00:00',
                'idempotency_key' => 'recebimento-v2-parcial-1',
            ]],
            'aplicar_status_ao_concluir' => true,
        ];

        $this->postJson("/api/v1/pedidos/{$pedido->id}/recebimentos", $primeiro)
            ->assertOk()
            ->assertJsonPath('status_aplicado', false)
            ->assertJsonPath('status_operacional.recebimento_fabrica.etapa', 'recebimento_parcial');
        $this->postJson("/api/v1/pedidos/{$pedido->id}/recebimentos", $primeiro)->assertOk();

        $entrega = $entrega->fresh();
        $this->assertSame(1, (int) $entrega->quantidade_recebida);
        $this->assertSame(1, (int) $entrega->quantidade_reservada);
        $this->assertSame(1, (int) Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $deposito->id)
            ->value('quantidade'));

        $this->postJson("/api/v1/pedidos/{$pedido->id}/recebimentos", [
            'itens' => [[
                'produto_entrega_item_id' => $entrega->id,
                'quantidade' => 1,
                'id_deposito_destino' => $deposito->id,
                'ocorrido_em' => '2026-07-10 10:00:00',
                'idempotency_key' => 'recebimento-v2-parcial-2',
            ]],
            'aplicar_status_ao_concluir' => true,
        ])->assertOk()
            ->assertJsonPath('status_aplicado', true)
            ->assertJsonPath('status_operacional.recebimento_fabrica.etapa', 'recebido_estoque');

        $entrega = $entrega->fresh();
        $this->assertSame(2, (int) $entrega->quantidade_recebida);
        $this->assertSame(2, (int) $entrega->quantidade_reservada);
        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::ENTREGA_ESTOQUE->value,
        ]);
        $this->assertDatabaseHas('produto_entrega_eventos', [
            'produto_entrega_item_id' => $entrega->id,
            'idempotency_key' => 'recebimento-v2-parcial-1',
            'ocorrido_em' => '2026-07-09 10:00:00',
        ]);
    }

    public function test_recebimento_vincula_idempotencia_ao_payload_e_nao_trunca_excesso(): void
    {
        [$usuario, $pedido, , $deposito] = $this->criarPedido(2, Pedido::ORIGEM_ABASTECIMENTO_FABRICA);
        Sanctum::actingAs($usuario);

        $service = app(EntregaProdutoService::class);
        $entrega = $service->criarDemandaPedido($pedido, $usuario->id, false)->firstOrFail();
        $url = "/api/v1/pedidos/{$pedido->id}/recebimentos";
        $payload = [
            'itens' => [[
                'produto_entrega_item_id' => $entrega->id,
                'quantidade' => 1,
                'id_deposito_destino' => $deposito->id,
                'ocorrido_em' => '2026-07-09 10:00:00',
                'idempotency_key' => 'recebimento-v2-payload-estavel',
            ]],
        ];

        $this->postJson($url, $payload)->assertOk();

        $payload['itens'][0]['ocorrido_em'] = '2026-07-10 10:00:00';
        $this->postJson($url, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['itens.0.idempotency_key']);

        try {
            $service->receberItem(
                $entrega->fresh(),
                $deposito->id,
                2,
                $usuario->id,
                idempotencyKey: 'recebimento-v2-excesso-concorrente',
                rejeitarExcesso: true
            );
            $this->fail('Recebimento acima do saldo pendente deveria ser rejeitado.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantidade', $exception->errors());
        }

        $this->assertSame(1, (int) $entrega->fresh()->quantidade_recebida);
        $this->assertSame(1, ProdutoEntregaEvento::query()
            ->where('tipo_evento', ProdutoEntregaEvento::RECEBIDO_ESTOQUE)
            ->where('produto_entrega_item_id', $entrega->id)
            ->count());
    }

    public function test_venda_de_catalogo_nao_exibe_recebimento_de_fabrica(): void
    {
        [, $pedido] = $this->criarPedido(1, Pedido::ORIGEM_ABASTECIMENTO_ESTOQUE);
        $service = app(EntregaProdutoService::class);
        $service->criarDemandaPedido($pedido, null, false);

        $status = $service->statusOperacionalPedido($pedido->fresh(['statusAtual', 'entregaItens']));

        $this->assertSame('nao_aplicavel', $status['recebimento_fabrica']['etapa']);
        $this->assertSame(0, $status['recebimento_fabrica']['quantidade_esperada']);
        $this->assertSame('registrar_entrega_cliente', $status['proxima_acao']);
    }

    public function test_resumo_exclui_devolucao_do_total_original_da_venda(): void
    {
        [, $pedido, $variacao] = $this->criarPedido(1, Pedido::ORIGEM_ABASTECIMENTO_FABRICA);
        app(EntregaProdutoService::class)->criarDemandaPedido($pedido, null, false);
        ProdutoEntregaItem::create([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_DEVOLUCAO,
            'origem_id' => 99,
            'pedido_id' => $pedido->id,
            'id_variacao' => $variacao->id,
            'quantidade_total' => 5,
            'quantidade_recebida' => 5,
            'status' => ProdutoEntregaItem::STATUS_RECEBIDO,
        ]);

        $resumo = app(EntregaProdutoService::class)->resumoPedido($pedido->fresh('entregaItens'));

        $this->assertSame(1, $resumo['quantidade_total']);
        $this->assertSame(5, $resumo['fluxos']['devolucoes']['quantidade_total']);
    }

    public function test_nota_exige_confirmacao_sem_saldo_e_grava_data_recebedor_sem_movimento(): void
    {
        [$usuario, $pedido] = $this->criarPedido(1, Pedido::ORIGEM_ABASTECIMENTO_FABRICA);
        Sanctum::actingAs($usuario);
        $entrega = app(EntregaProdutoService::class)->criarDemandaPedido($pedido, $usuario->id, false)->firstOrFail();
        $payload = [
            'acao' => 'registrar_entrega',
            'data_entrega' => '2026-07-08',
            'recebedor' => 'Maria da Silva',
            'idempotency_key' => 'nota-v2-sem-saldo',
            'itens' => [[
                'produto_entrega_item_id' => $entrega->id,
                'quantidade' => 1,
            ]],
        ];

        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'ENTREGA_SEM_SALDO_REQUER_CONFIRMACAO')
            ->assertJsonPath('itens.0.quantidade', 1)
            ->assertJsonPath('itens.0.disponivel', 0)
            ->assertJsonPath('itens.0.faltante', 1);

        $this->assertSame(0, ProdutoEntregaEvento::query()
            ->where('produto_entrega_item_id', $entrega->id)
            ->where('tipo_evento', ProdutoEntregaEvento::ENTREGUE_CLIENTE)
            ->count());

        $payload['confirmar_entrega_sem_saldo'] = true;
        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", $payload)->assertOk();
        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", $payload)->assertOk();

        $evento = ProdutoEntregaEvento::query()
            ->where('idempotency_key', "nota-entrega:nota-v2-sem-saldo:item:{$entrega->id}:entregar-sem-saldo")
            ->firstOrFail();
        $this->assertSame('2026-07-08', $evento->ocorrido_em?->toDateString());
        $this->assertSame('Maria da Silva', $evento->metadata_json['recebedor']);
        $this->assertTrue((bool) $evento->metadata_json['confirmado_sem_saldo']);
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count());
        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::ENTREGA_CLIENTE->value,
        ]);
    }

    public function test_chegada_da_fabrica_apos_venda_antecipada_nao_regride_status_comercial(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedido(1, Pedido::ORIGEM_ABASTECIMENTO_FABRICA);
        Sanctum::actingAs($usuario);
        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 1]
        );

        $service = app(EntregaProdutoService::class);
        $entrega = $service->criarDemandaPedido($pedido, $usuario->id, false)->firstOrFail();
        $service->reservarItem($entrega, $deposito->id, 1, $usuario->id, 'Antecipacao', 'antecipacao-status-1');
        $service->expedirItem($entrega, $deposito->id, 1, $usuario->id, 'Saida antecipada', idempotencyKey: 'antecipacao-status-2');
        $service->entregarItem($entrega, 1, $usuario->id, 'Entrega antecipada', 'antecipacao-status-3');
        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::ENTREGA_CLIENTE,
            'data_status' => now(),
            'usuario_id' => $usuario->id,
        ]);

        $this->postJson("/api/v1/pedidos/{$pedido->id}/recebimentos", [
            'itens' => [[
                'produto_entrega_item_id' => $entrega->id,
                'quantidade' => 1,
                'id_deposito_destino' => $deposito->id,
                'ocorrido_em' => '2026-07-10 12:00:00',
                'idempotency_key' => 'reposicao-pos-entrega-1',
            ]],
            'aplicar_status_ao_concluir' => true,
        ])->assertOk();

        $ultimo = $pedido->historicoStatus()->latest('data_status')->latest('id')->firstOrFail();
        $this->assertSame(PedidoStatus::ENTREGA_CLIENTE->value, $ultimo->getRawOriginal('status'));
        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::ENTREGA_ESTOQUE->value,
        ]);
        $this->assertSame(1, (int) Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $deposito->id)
            ->value('quantidade'));
    }

    public function test_auditoria_do_fluxo_e_somente_dry_run_e_aceita_numero_externo(): void
    {
        [, $pedido] = $this->criarPedido(1, Pedido::ORIGEM_ABASTECIMENTO_FABRICA, '20009');
        $entrega = app(EntregaProdutoService::class)->criarDemandaPedido($pedido, null, false)->firstOrFail();
        $entrega->update([
            'quantidade_expedida' => 1,
            'quantidade_entregue' => 1,
            'status' => ProdutoEntregaItem::STATUS_ENTREGUE,
        ]);
        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::ENTREGA_ESTOQUE,
            'data_status' => now()->addSecond(),
        ]);
        ProdutoEntregaEvento::create([
            'produto_entrega_item_id' => $entrega->id,
            'tipo_evento' => ProdutoEntregaEvento::ENTREGUE_CLIENTE,
            'quantidade' => 1,
            'ocorrido_em' => now(),
            'idempotency_key' => 'auditoria-pedido-20009-entrega',
        ]);

        $eventosAntes = ProdutoEntregaEvento::count();
        $this->artisan('pedidos:auditar-fluxo --pedido=20009 --json')
            ->expectsOutputToContain('recebimento_ausente')
            ->assertExitCode(0);

        $this->assertSame($eventosAntes, ProdutoEntregaEvento::count());
        $this->assertSame(1, (int) $entrega->fresh()->quantidade_entregue);
    }

    public function test_nota_com_quantidade_autoaloca_saldo_real_e_baixa_estoque(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedido(1, Pedido::ORIGEM_ABASTECIMENTO_FABRICA);
        Sanctum::actingAs($usuario);
        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 1]
        );
        $entrega = app(EntregaProdutoService::class)->criarDemandaPedido($pedido, $usuario->id, false)->firstOrFail();

        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", [
            'acao' => 'registrar_entrega',
            'data_entrega' => '2026-07-10',
            'idempotency_key' => 'nota-v2-autoalocada',
            'itens' => [[
                'produto_entrega_item_id' => $entrega->id,
                'quantidade' => 1,
            ]],
        ])->assertOk();

        $entrega = $entrega->fresh();
        $this->assertSame(1, (int) $entrega->quantidade_expedida);
        $this->assertSame(1, (int) $entrega->quantidade_entregue);
        $this->assertSame(0, (int) Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $deposito->id)
            ->value('quantidade'));
        $this->assertSame(1, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->count());
    }

    public function test_registro_de_entrega_exige_permissao_e_bloqueia_pedido_divergente(): void
    {
        [$usuario, $pedido] = $this->criarPedido(1, Pedido::ORIGEM_ABASTECIMENTO_FABRICA);
        Sanctum::actingAs($usuario);
        $entrega = app(EntregaProdutoService::class)->criarDemandaPedido($pedido, $usuario->id, false)->firstOrFail();
        $payload = [
            'acao' => 'registrar_entrega',
            'data_entrega' => '2026-07-10',
            'idempotency_key' => 'nota-v2-seguranca',
            'itens' => [[
                'produto_entrega_item_id' => $entrega->id,
                'quantidade' => 1,
            ]],
        ];

        Cache::put('permissoes_usuario_'.$usuario->id, [], now()->addHour());
        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", $payload)->assertForbidden();

        Cache::put('permissoes_usuario_'.$usuario->id, ['estoque.movimentar'], now()->addHour());
        $entrega->update([
            'quantidade_expedida' => 1,
            'quantidade_entregue' => 1,
            'status' => ProdutoEntregaItem::STATUS_ENTREGUE,
        ]);
        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::ENTREGA_ESTOQUE,
            'data_status' => now()->addSecond(),
            'usuario_id' => $usuario->id,
        ]);

        $this->postJson("/api/v1/pedidos/{$pedido->id}/pdf/nota-entrega", $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'PEDIDO_DIVERGENTE_REQUER_RECONCILIACAO');
    }

    private function criarPedido(int $quantidade, string $origem, ?string $numero = null): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Fluxo V2',
            'email' => uniqid('fluxo-v2-', true).'@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        Cache::put('permissoes_usuario_'.$usuario->id, ['estoque.movimentar'], now()->addHour());
        $cliente = Cliente::create([
            'nome' => 'Cliente Fluxo V2',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);
        $categoria = Categoria::create(['nome' => 'Categoria Fluxo V2']);
        $produto = Produto::create([
            'nome' => 'Produto Fluxo V2',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => uniqid('FLUXO-', false),
            'nome' => 'Variacao Fluxo V2',
            'preco' => 100,
            'custo' => 50,
        ]);
        $deposito = Deposito::create(['nome' => 'Deposito Fluxo V2']);
        $pedido = Pedido::create([
            'tipo' => Pedido::TIPO_VENDA,
            'origem_abastecimento' => $origem,
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'numero_externo' => $numero,
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

        return [$usuario, $pedido->fresh(['itens', 'statusAtual']), $variacao, $deposito];
    }
}
