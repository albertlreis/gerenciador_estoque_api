<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueReserva;
use App\Models\Fornecedor;
use App\Models\Pedido;
use App\Models\PedidoItem;
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

class PedidoAntecipacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_exige_permissao_operacional_e_valida_contexto_canonico_da_venda_de_fabrica(): void
    {
        [$usuario, $pedido, $item, $variacao, $deposito] = $this->criarContexto(2);
        Sanctum::actingAs($usuario);
        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 2]
        );
        Cache::put('permissoes_usuario_'.$usuario->id, [], now()->addHour());

        $payload = [
            'deposito_id' => $deposito->id,
            'quantidade' => 1,
            'idempotency_key' => 'antecipacao-sem-permissao',
        ];
        $this->postJson($this->urlRegistrar($pedido, $item), $payload)->assertForbidden();

        Cache::put('permissoes_usuario_'.$usuario->id, ['estoque.movimentar'], now()->addHour());
        $pedidoEstoque = Pedido::create([
            'tipo' => Pedido::TIPO_VENDA,
            'origem_abastecimento' => Pedido::ORIGEM_ABASTECIMENTO_ESTOQUE,
            'id_cliente' => $pedido->id_cliente,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 100,
        ]);
        $itemEstoque = PedidoItem::create([
            'id_pedido' => $pedidoEstoque->id,
            'id_variacao' => $variacao->id,
            'quantidade' => 1,
            'preco_unitario' => 100,
            'subtotal' => 100,
        ]);

        $this->postJson($this->urlRegistrar($pedidoEstoque, $itemEstoque), [
            ...$payload,
            'idempotency_key' => 'antecipacao-origem-invalida',
        ])->assertUnprocessable()->assertJsonValidationErrors('pedido');

        $this->postJson($this->urlRegistrar($pedido, $itemEstoque), [
            ...$payload,
            'idempotency_key' => 'antecipacao-item-outro-pedido',
        ])->assertUnprocessable()->assertJsonValidationErrors('item');

        $this->postJson($this->urlRegistrar($pedido, $item), [
            ...$payload,
            'quantidade' => 3,
            'idempotency_key' => 'antecipacao-acima-pendente',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantidade');
    }

    public function test_registra_antecipacao_idempotente_e_retorna_disponibilidade_liquida_no_detalhe(): void
    {
        [$usuario, $pedido, $item, $variacao, $deposito] = $this->criarContexto(2);
        Sanctum::actingAs($usuario);
        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 3]
        );
        $payload = [
            'deposito_id' => $deposito->id,
            'quantidade' => 1,
            'observacao' => 'Cliente precisa de uma unidade agora.',
            'idempotency_key' => 'antecipacao-registro-idempotente',
        ];

        $this->postJson($this->urlRegistrar($pedido, $item), $payload)
            ->assertOk()
            ->assertJsonPath('data.antecipacao.ativa', true)
            ->assertJsonPath('data.antecipacao.quantidade_reservada', 1)
            ->assertJsonPath('data.antecipacao.quantidade_aguardando_fabrica', 2)
            ->assertJsonPath('data.antecipacao.deposito_origem_id', $deposito->id)
            ->assertJsonPath('data.antecipacao.depositos_disponiveis.0.quantidade_disponivel', 2);
        $this->postJson($this->urlRegistrar($pedido, $item), $payload)->assertOk();

        $entrega = ProdutoEntregaItem::query()->where('pedido_item_id', $item->id)->firstOrFail();
        $this->assertSame(1, (int) $entrega->quantidade_reservada);
        $this->assertSame(1, EstoqueReserva::query()->where('pedido_item_id', $item->id)->count());
        $this->assertSame(1, ProdutoEntregaEvento::query()
            ->where('idempotency_key', $payload['idempotency_key'])
            ->count());

        $this->getJson("/api/v1/pedidos/{$pedido->id}/detalhado")
            ->assertOk()
            ->assertJsonPath('data.antecipacao.quantidade_reservada', 1)
            ->assertJsonPath('data.antecipacao.quantidade_aguardando_fabrica', 2)
            ->assertJsonPath('data.entrega_itens.0.antecipacao.quantidade_reservada', 1)
            ->assertJsonPath('data.entrega_itens.0.antecipacao.depositos_disponiveis.0.quantidade_disponivel', 2);
    }

    public function test_cancelamento_remove_todas_as_reservas_antecipadas_nao_consumidas_e_e_idempotente(): void
    {
        [$usuario, $pedido, $item, $variacao, $deposito] = $this->criarContexto(3);
        Sanctum::actingAs($usuario);
        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 3]
        );

        foreach ([1, 2] as $indice) {
            $this->postJson($this->urlRegistrar($pedido, $item), [
                'deposito_id' => $deposito->id,
                'quantidade' => 1,
                'idempotency_key' => "antecipacao-registro-{$indice}",
            ])->assertOk();
        }

        $payloadCancelar = [
            'observacao' => 'Cliente aguardara a fabrica.',
            'idempotency_key' => 'antecipacao-cancelar-todas',
        ];
        $this->postJson($this->urlCancelar($pedido, $item), $payloadCancelar)
            ->assertOk()
            ->assertJsonPath('data.antecipacao.ativa', false)
            ->assertJsonPath('data.antecipacao.quantidade_reservada', 0)
            ->assertJsonPath('data.antecipacao.quantidade_aguardando_fabrica', 3);
        $this->postJson($this->urlCancelar($pedido, $item), $payloadCancelar)->assertOk();

        $entrega = ProdutoEntregaItem::query()->where('pedido_item_id', $item->id)->firstOrFail();
        $this->assertSame(0, (int) $entrega->quantidade_reservada);
        $this->assertNull($entrega->id_deposito_origem);
        $this->assertSame(2, EstoqueReserva::query()
            ->where('pedido_item_id', $item->id)
            ->where('status', 'cancelada')
            ->count());
        $this->assertSame(2, ProdutoEntregaEvento::query()
            ->where('produto_entrega_item_id', $entrega->id)
            ->where('tipo_evento', ProdutoEntregaEvento::RESERVA_CANCELADA)
            ->count());
    }

    public function test_nao_cancela_antecipacao_ja_consumida(): void
    {
        [$usuario, $pedido, $item, $variacao, $deposito] = $this->criarContexto(2);
        Sanctum::actingAs($usuario);
        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 2]
        );
        $this->postJson($this->urlRegistrar($pedido, $item), [
            'deposito_id' => $deposito->id,
            'quantidade' => 2,
            'idempotency_key' => 'antecipacao-consumida-registro',
        ])->assertOk();

        EstoqueReserva::query()->where('pedido_item_id', $item->id)->update([
            'quantidade_consumida' => 1,
            'status' => 'ativa',
        ]);

        $this->getJson("/api/v1/pedidos/{$pedido->id}/detalhado")
            ->assertOk()
            ->assertJsonPath('data.entrega_itens.0.antecipacao.cancelavel', false)
            ->assertJsonPath('data.entrega_itens.0.antecipacao.quantidade_cancelavel', 0);

        $this->postJson($this->urlCancelar($pedido, $item), [
            'idempotency_key' => 'antecipacao-consumida-cancelamento',
        ])->assertUnprocessable()->assertJsonValidationErrors('item');
        $this->assertSame(0, ProdutoEntregaEvento::query()
            ->where('idempotency_key', 'antecipacao-consumida-cancelamento')
            ->count());
    }

    public function test_put_preserva_origem_e_progresso_operacional_de_antecipacao_consumida(): void
    {
        [$usuario, $pedido, $item, $variacao, $deposito] = $this->criarContexto(2);
        Sanctum::actingAs($usuario);
        Cache::put(
            'permissoes_usuario_'.$usuario->id,
            ['estoque.movimentar', 'pedidos.editar'],
            now()->addHour()
        );
        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 2]
        );
        $this->postJson($this->urlRegistrar($pedido, $item), [
            'deposito_id' => $deposito->id,
            'quantidade' => 1,
            'idempotency_key' => 'antecipacao-progresso-registro',
        ])->assertOk();
        $entrega = ProdutoEntregaItem::query()->where('pedido_item_id', $item->id)->firstOrFail();
        $service = app(EntregaProdutoService::class);
        $service->expedirItem(
            $entrega,
            $deposito->id,
            1,
            $usuario->id,
            'Expedicao antecipada',
            idempotencyKey: 'antecipacao-progresso-expedicao'
        );
        $service->entregarItem(
            $entrega,
            1,
            $usuario->id,
            'Entrega antecipada',
            'antecipacao-progresso-entrega'
        );

        $this->putJson("/api/v1/pedidos/{$pedido->id}", [
            'itens' => [[
                'id' => $item->id,
                'id_variacao' => $variacao->id,
                'quantidade' => 2,
                'preco_unitario' => 100,
                'id_deposito' => null,
            ]],
        ])->assertOk();

        $entrega = $entrega->fresh();
        $this->assertSame((int) $deposito->id, (int) $entrega->id_deposito_origem);
        $this->assertSame(1, (int) $entrega->quantidade_reservada);
        $this->assertSame(1, (int) $entrega->quantidade_expedida);
        $this->assertSame(1, (int) $entrega->quantidade_entregue);
        $this->assertSame(ProdutoEntregaItem::STATUS_RESERVADO, $entrega->status);
        $this->assertSame(1, ProdutoEntregaEvento::query()
            ->where('produto_entrega_item_id', $entrega->id)
            ->where('tipo_evento', ProdutoEntregaEvento::RESERVA_CRIADA)
            ->count());
    }

    public function test_put_atualiza_fornecedor_sem_reservar_estoque_antigo_em_venda_de_fabrica(): void
    {
        [$usuario, $pedido, $item, $variacao, $deposito] = $this->criarContexto(1);
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_'.$usuario->id, ['pedidos.editar'], now()->addHour());
        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 5]
        );
        $fornecedor = Fornecedor::create([
            'nome' => 'Fornecedor pos importacao',
            'cnpj' => '12345678000199',
            'status' => 1,
        ]);

        $this->putJson("/api/v1/pedidos/{$pedido->id}", [
            'id_fornecedor' => $fornecedor->id,
            'itens' => [[
                'id' => $item->id,
                'id_variacao' => $variacao->id,
                'quantidade' => 1,
                'preco_unitario' => 100,
                'id_deposito' => $deposito->id,
            ]],
        ])->assertOk()->assertJsonPath('data.id_fornecedor', $fornecedor->id);

        $this->assertSame((int) $fornecedor->id, (int) $pedido->fresh()->id_fornecedor);
        $entrega = ProdutoEntregaItem::query()->where('pedido_item_id', $item->id)->firstOrFail();
        $this->assertSame(0, (int) $entrega->quantidade_reservada);
        $this->assertSame(0, EstoqueReserva::query()->where('pedido_item_id', $item->id)->count());
        $this->assertSame(0, ProdutoEntregaEvento::query()
            ->where('produto_entrega_item_id', $entrega->id)
            ->where('tipo_evento', ProdutoEntregaEvento::RESERVA_CRIADA)
            ->count());
    }

    public function test_pedido_de_fabrica_sem_deposito_sugerido_aguarda_recebimento_sem_divergencia(): void
    {
        [$usuario, $pedido, $item] = $this->criarContexto(1);
        Sanctum::actingAs($usuario);
        $item->update(['id_deposito' => null]);

        app(EntregaProdutoService::class)->reconciliarPedidoEditado($pedido->fresh('itens'));

        $entrega = ProdutoEntregaItem::query()->where('pedido_item_id', $item->id)->firstOrFail();
        $this->assertFalse((bool) $entrega->em_revisao);
        $this->assertNull($entrega->bloqueio_motivo);
        $this->assertNull($entrega->id_deposito_destino);
        $status = app(EntregaProdutoService::class)
            ->statusOperacionalPedido($pedido->fresh(['statusAtual', 'entregaItens']));
        $this->assertFalse($status['divergencia']);
        $this->assertSame('aguardando_fabrica', $status['recebimento_fabrica']['etapa']);
        $this->getJson("/api/v1/pedidos/{$pedido->id}/detalhado")
            ->assertOk()
            ->assertJsonPath('data.entrega_itens.0.etapa_operacional', 'aguardando_fabrica')
            ->assertJsonPath('data.entrega_itens.0.proxima_acao', 'registrar_recebimento_estoque');
    }

    /** @return array{Usuario,Pedido,PedidoItem,ProdutoVariacao,Deposito} */
    private function criarContexto(int $quantidade): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Antecipacao',
            'email' => uniqid('antecipacao-', true).'@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        Cache::put('permissoes_usuario_'.$usuario->id, ['estoque.movimentar'], now()->addHour());
        $cliente = Cliente::create([
            'nome' => 'Cliente Antecipacao',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);
        $categoria = Categoria::create(['nome' => 'Categoria Antecipacao']);
        $produto = Produto::create([
            'nome' => 'Produto Antecipacao',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => uniqid('ANT-', false),
            'nome' => 'Variacao Antecipacao',
            'preco' => 100,
            'custo' => 50,
        ]);
        $deposito = Deposito::create(['nome' => 'Deposito Antecipacao']);
        $pedido = Pedido::create([
            'tipo' => Pedido::TIPO_VENDA,
            'origem_abastecimento' => Pedido::ORIGEM_ABASTECIMENTO_FABRICA,
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'numero_externo' => uniqid('PED-ANT-', false),
            'data_pedido' => now(),
            'valor_total' => $quantidade * 100,
        ]);
        $item = PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => $quantidade,
            'preco_unitario' => 100,
            'subtotal' => $quantidade * 100,
        ]);
        app(EntregaProdutoService::class)->criarDemandaPedido($pedido->fresh('itens'), $usuario->id, false);

        return [$usuario, $pedido->fresh(), $item->fresh(), $variacao, $deposito];
    }

    private function urlRegistrar(Pedido $pedido, PedidoItem $item): string
    {
        return "/api/v1/pedidos/{$pedido->id}/itens/{$item->id}/antecipacao";
    }

    private function urlCancelar(Pedido $pedido, PedidoItem $item): string
    {
        return $this->urlRegistrar($pedido, $item).'/cancelar';
    }
}
