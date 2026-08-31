<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\Parceiro;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Produto;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use App\Services\EntregaProdutoService;
use App\Services\AuditoriaEventoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        $logId = DB::table('auditoria_logs')
            ->where('modulo', 'pedidos')
            ->where('acao', 'pedido.updated')
            ->where('entity_id', (string) $pedido->id)
            ->latest('id')
            ->value('id');

        $this->assertNotNull($logId);
        $this->assertDatabaseHas('auditoria_log_mudancas', [
            'auditoria_log_id' => $logId,
            'campo' => 'numero_externo',
            'old_value' => 'PED-001',
            'new_value' => 'PED-EDIT',
        ]);
        $this->assertDatabaseHas('auditoria_log_mudancas', [
            'auditoria_log_id' => $logId,
            'campo' => 'itens',
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

    public function test_bloqueia_variacao_e_quantidade_incompativeis_apos_evento_processado(): void
    {
        [$pedido, $item, $variacaoA, $variacaoB, $deposito] = $this->seedBase();

        $item->update([
            'quantidade' => 3,
            'subtotal' => 300,
        ]);

        $entrega = ProdutoEntregaItem::create([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
            'origem_id' => $pedido->id,
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $item->id,
            'id_variacao' => $variacaoA->id,
            'quantidade_total' => 3,
            'quantidade_reservada' => 2,
            'id_deposito_origem' => $deposito->id,
            'status' => ProdutoEntregaItem::STATUS_RESERVADO,
        ]);

        ProdutoEntregaEvento::create([
            'produto_entrega_item_id' => $entrega->id,
            'tipo_evento' => ProdutoEntregaEvento::RESERVA_CRIADA,
            'quantidade' => 2,
            'id_deposito_origem' => $deposito->id,
            'idempotency_key' => "pedido-update:{$pedido->id}:reserva-processada",
        ]);

        $this->putJson("/api/v1/pedidos/{$pedido->id}", [
            'itens' => [[
                'id' => $item->id,
                'id_variacao' => $variacaoB->id,
                'quantidade' => 3,
                'preco_unitario' => 100,
                'id_deposito' => $deposito->id,
            ]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('itens.0.id_variacao');

        $this->putJson("/api/v1/pedidos/{$pedido->id}", [
            'itens' => [[
                'id' => $item->id,
                'id_variacao' => $variacaoA->id,
                'quantidade' => 1,
                'preco_unitario' => 100,
                'id_deposito' => $deposito->id,
            ]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('itens.0.quantidade');

        $this->assertDatabaseHas('pedido_itens', [
            'id' => $item->id,
            'id_variacao' => $variacaoA->id,
            'quantidade' => 3,
        ]);
        $this->assertSame(2, (int) $entrega->fresh()->quantidade_reservada);
        $this->assertSame(1, ProdutoEntregaEvento::query()
            ->where('produto_entrega_item_id', $entrega->id)
            ->where('tipo_evento', ProdutoEntregaEvento::RESERVA_CRIADA)
            ->count());
    }

    public function test_permite_editar_numero_externo_para_valor_ja_usado_por_outro_pedido(): void
    {
        [$pedido, , , , , $cliente, $parceiro] = $this->seedBase();

        Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $pedido->id_usuario,
            'id_parceiro' => $parceiro->id,
            'tipo' => 'venda',
            'numero_externo' => 'PED-DUP',
            'data_pedido' => now(),
            'valor_total' => 0,
        ]);

        $response = $this->putJson("/api/v1/pedidos/{$pedido->id}", [
            'numero_externo' => 'PED-DUP',
        ]);

        $response->assertOk();

        $this->assertSame(2, Pedido::query()->where('numero_externo', 'PED-DUP')->count());
    }

    public function test_conversao_processada_exige_reconciliacao_sem_alterar_pedido(): void
    {
        [$pedido, $item, $variacao, , $deposito, $cliente] = $this->seedBase();
        [$entrega] = $this->prepararReposicaoRecebida($pedido, $item, $variacao, $deposito, 2);

        $response = $this->putJson("/api/v1/pedidos/{$pedido->id}", [
            'tipo' => Pedido::TIPO_VENDA,
            'id_cliente' => $cliente->id,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'CONVERSAO_PEDIDO_REQUER_RECONCILIACAO')
            ->assertJsonPath('itens.0.produto_entrega_item_id', $entrega->id)
            ->assertJsonPath('itens.0.quantidade_recebida', 2);
        $this->assertSame(Pedido::TIPO_REPOSICAO, $pedido->fresh()->tipo);
        $this->assertSame(2, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
    }

    public function test_conversao_sem_processamento_continua_permitida_diretamente(): void
    {
        [$pedido, , , , , $cliente] = $this->seedBase();
        $pedido->update(['tipo' => Pedido::TIPO_REPOSICAO, 'id_cliente' => null]);

        $this->putJson("/api/v1/pedidos/{$pedido->id}", [
            'tipo' => Pedido::TIPO_VENDA,
            'id_cliente' => $cliente->id,
        ])->assertOk();

        $this->assertSame(Pedido::TIPO_VENDA, $pedido->fresh()->tipo);
        $this->assertSame($cliente->id, (int) $pedido->fresh()->id_cliente);
    }

    public function test_conversao_guiada_pendente_preserva_saldo_e_reabre_fluxo_cliente(): void
    {
        [$pedido, $item, $variacao, , $deposito, $cliente] = $this->seedBase();
        $this->prepararReposicaoRecebida($pedido, $item, $variacao, $deposito, 2);

        $this->putJson("/api/v1/pedidos/{$pedido->id}", [
            'tipo' => Pedido::TIPO_VENDA,
            'id_cliente' => $cliente->id,
            'conversao_fluxo' => [
                'modo' => 'entrega_pendente',
                'idempotency_key' => "conversao:{$pedido->id}:pendente",
            ],
        ])->assertOk();

        $this->assertSame(Pedido::TIPO_VENDA, $pedido->fresh()->tipo);
        $this->assertSame(2, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame('entrega_pendente', $pedido->historicoStatus()->latest('id')->value('status'));
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->whereNotNull('id_deposito_origem')->count());
    }

    public function test_conversao_guiada_confirmada_baixa_saldo_entrega_e_e_idempotente(): void
    {
        [$pedido, $item, $variacao, , $deposito, $cliente] = $this->seedBase();
        [$entrega] = $this->prepararReposicaoRecebida($pedido, $item, $variacao, $deposito, 2);
        $payload = [
            'tipo' => Pedido::TIPO_VENDA,
            'id_cliente' => $cliente->id,
            'conversao_fluxo' => [
                'modo' => 'entrega_confirmada',
                'ocorrido_em' => '2026-08-03',
                'idempotency_key' => "conversao:{$pedido->id}:confirmada",
                'itens' => [[
                    'produto_entrega_item_id' => $entrega->id,
                    'id_deposito' => $deposito->id,
                    'quantidade' => 2,
                ]],
            ],
        ];

        $this->putJson("/api/v1/pedidos/{$pedido->id}", $payload)->assertOk();
        $this->putJson("/api/v1/pedidos/{$pedido->id}", $payload)->assertOk();

        $entrega->refresh();
        $this->assertSame(2, (int) $entrega->quantidade_expedida);
        $this->assertSame(2, (int) $entrega->quantidade_entregue);
        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(1, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->where('tipo', 'saida_entrega_cliente')->count());
        $this->assertSame(1, ProdutoEntregaEvento::query()->where('idempotency_key', "conversao:{$pedido->id}:confirmada:item:{$entrega->id}:entregar")->count());
        $this->assertSame('finalizado', $pedido->historicoStatus()->latest('id')->value('status'));
    }

    public function test_conversao_confirmada_reaproveita_expedicao_anterior_sem_baixa_duplicada(): void
    {
        [$pedido, $item, $variacao, , $deposito, $cliente] = $this->seedBase();
        [$entrega] = $this->prepararReposicaoRecebida($pedido, $item, $variacao, $deposito, 2);
        $entrega = app(EntregaProdutoService::class)->expedirItem(
            $entrega,
            $deposito->id,
            1,
            $pedido->id_usuario,
            'Expedição anterior à conversão',
            ProdutoEntregaEvento::EXPEDIDO_CLIENTE,
            "conversao:{$pedido->id}:expedicao-anterior"
        );

        $this->putJson("/api/v1/pedidos/{$pedido->id}", [
            'tipo' => Pedido::TIPO_VENDA,
            'id_cliente' => $cliente->id,
            'conversao_fluxo' => [
                'modo' => 'entrega_confirmada',
                'ocorrido_em' => '2026-08-03',
                'idempotency_key' => "conversao:{$pedido->id}:parcial",
                'itens' => [[
                    'produto_entrega_item_id' => $entrega->id,
                    'id_deposito' => $deposito->id,
                    'quantidade' => 2,
                ]],
            ],
        ])->assertOk();

        $entrega->refresh();
        $this->assertSame(2, (int) $entrega->quantidade_expedida);
        $this->assertSame(2, (int) $entrega->quantidade_entregue);
        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(2, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->where('tipo', 'saida_entrega_cliente')->count());
    }

    public function test_conversao_confirmada_sem_saldo_faz_rollback_integral(): void
    {
        [$pedido, $item, $variacao, , $deposito, $cliente] = $this->seedBase();
        [$entrega] = $this->prepararReposicaoRecebida($pedido, $item, $variacao, $deposito, 2);
        Estoque::query()->where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->update(['quantidade' => 1]);

        $this->putJson("/api/v1/pedidos/{$pedido->id}", [
            'tipo' => Pedido::TIPO_VENDA,
            'id_cliente' => $cliente->id,
            'conversao_fluxo' => [
                'modo' => 'entrega_confirmada',
                'ocorrido_em' => '2026-08-03',
                'idempotency_key' => "conversao:{$pedido->id}:sem-saldo",
                'itens' => [[
                    'produto_entrega_item_id' => $entrega->id,
                    'id_deposito' => $deposito->id,
                    'quantidade' => 2,
                ]],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('conversao_fluxo.itens');

        $this->assertSame(Pedido::TIPO_REPOSICAO, $pedido->fresh()->tipo);
        $this->assertSame(0, (int) $entrega->fresh()->quantidade_expedida);
        $this->assertSame(0, EstoqueMovimentacao::query()->where('pedido_id', $pedido->id)->where('tipo', 'saida_entrega_cliente')->count());
    }

    public function test_auditoria_detecta_venda_finalizada_convertida_sem_saida_e_saldo_como_evidencia(): void
    {
        [$pedido, $item, $variacao, , $deposito, $cliente] = $this->seedBase();
        $this->prepararReposicaoRecebida($pedido, $item, $variacao, $deposito, 2);
        $pedido->update(['tipo' => Pedido::TIPO_VENDA, 'id_cliente' => $cliente->id]);
        $auditoria = app(AuditoriaEventoService::class)->registrar(
            module: 'pedidos',
            action: 'pedido.updated',
            label: "Pedido #{$pedido->id} atualizado",
            auditable: $pedido,
            mudancas: [[
                'campo' => 'tipo',
                'old' => Pedido::TIPO_REPOSICAO,
                'new' => Pedido::TIPO_VENDA,
                'value_type' => 'string',
            ]]
        );
        $this->assertNotNull($auditoria);
        $this->assertDatabaseHas('auditoria_logs', [
            'id' => $auditoria->id,
            'entity_type' => Pedido::class,
            'entity_id' => (string) $pedido->id,
        ]);
        $this->assertDatabaseHas('auditoria_log_mudancas', [
            'auditoria_log_id' => $auditoria->id,
            'campo' => 'tipo',
            'old_value' => Pedido::TIPO_REPOSICAO,
            'new_value' => Pedido::TIPO_VENDA,
        ]);
        $this->assertSame(0, Artisan::call('pedidos:auditar-fluxo', [
            '--pedido' => (string) $pedido->id,
            '--json' => true,
        ]));
        $saida = Artisan::output();
        $this->assertStringContainsString('venda_finalizada_sem_entrega', $saida);
        $this->assertStringContainsString('conversao_reposicao_venda_sem_saida', $saida);
        $this->assertStringContainsString('saldo_positivo_evidencia_auxiliar', $saida);
    }

    private function prepararReposicaoRecebida(
        Pedido $pedido,
        PedidoItem $item,
        ProdutoVariacao $variacao,
        Deposito $deposito,
        int $quantidade
    ): array {
        $pedido->update([
            'tipo' => Pedido::TIPO_REPOSICAO,
            'id_cliente' => null,
            'origem_abastecimento' => Pedido::ORIGEM_ABASTECIMENTO_FABRICA,
        ]);
        $item->update(['quantidade' => $quantidade, 'subtotal' => $quantidade * 100]);

        $service = app(EntregaProdutoService::class);
        $entrega = $service->criarDemandaPedido($pedido->fresh(), $pedido->id_usuario, false)->firstOrFail();
        $service->receberItem(
            $entrega,
            $deposito->id,
            $quantidade,
            $pedido->id_usuario,
            'Recebimento para teste de conversão',
            "conversao-recebimento:{$pedido->id}"
        );

        return [$entrega->fresh(), $variacao, $deposito];
    }
}
