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
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use App\Services\EntregaProdutoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidoEstornoOperacionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_estorna_recebimento_parcial_sem_apagar_movimento_original_e_eh_idempotente(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedido(3, Pedido::TIPO_REPOSICAO, Pedido::ORIGEM_ABASTECIMENTO_FABRICA);
        Sanctum::actingAs($usuario);
        $fluxo = app(EntregaProdutoService::class);
        $item = $fluxo->criarDemandaPedido($pedido, $usuario->id, false)->firstOrFail();
        $item = $fluxo->receberItem($item, $deposito->id, 3, $usuario->id, idempotencyKey: 'receber-tres');
        $eventoOriginal = $item->eventos->firstWhere('tipo_evento', ProdutoEntregaEvento::RECEBIDO_ESTOQUE);
        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::FINALIZADO->value,
            'observacoes' => 'Pedido finalizado automaticamente apos recebimento total dos produtos.',
        ]);

        $payload = [
            'produto_entrega_item_id' => $item->id,
            'tipo' => 'recebimento_fabrica',
            'quantidade' => 1,
            'motivo' => 'Unidade recebida por engano.',
            'idempotency_key' => 'estorno-recebimento-parcial',
        ];

        $this->postJson("/api/v1/pedidos/{$pedido->id}/estornos-operacionais", $payload)
            ->assertOk()
            ->assertJsonPath('data.repetido', false)
            ->assertJsonPath('data.item.quantidade_recebida', 2);

        $this->assertDatabaseHas('produto_entrega_eventos', [
            'id' => $eventoOriginal->id,
            'tipo_evento' => ProdutoEntregaEvento::RECEBIDO_ESTOQUE,
            'quantidade' => 3,
        ]);
        $this->assertDatabaseHas('estoque_movimentacoes', [
            'ref_type' => 'estorno',
            'ref_id' => $eventoOriginal->estoque_movimentacao_id,
            'quantidade' => 1,
        ]);
        $this->assertSame(2, (int) Estoque::where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::FINALIZADO->value,
            'observacoes' => 'Pedido finalizado automaticamente apos recebimento total dos produtos.',
        ]);

        $this->postJson("/api/v1/pedidos/{$pedido->id}/estornos-operacionais", $payload)
            ->assertOk()
            ->assertJsonPath('data.repetido', true)
            ->assertJsonPath('data.item.quantidade_recebida', 2);
        $this->assertSame(1, EstoqueMovimentacao::where('ref_type', 'estorno')->where('ref_id', $eventoOriginal->estoque_movimentacao_id)->count());
    }

    public function test_estorno_de_entrega_pode_manter_item_em_entrega_sem_repor_estoque(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedido(2, Pedido::TIPO_VENDA, Pedido::ORIGEM_ABASTECIMENTO_ESTOQUE);
        Sanctum::actingAs($usuario);
        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 2]
        );
        $fluxo = app(EntregaProdutoService::class);
        $item = $fluxo->criarDemandaPedido($pedido, $usuario->id, true)->firstOrFail();
        $item = $fluxo->expedirItem($item, $deposito->id, 2, $usuario->id, idempotencyKey: 'expedir-dois');
        $fluxo->entregarItem($item, 2, $usuario->id, idempotencyKey: 'entregar-dois');

        $this->postJson("/api/v1/pedidos/{$pedido->id}/estornos-operacionais", [
            'produto_entrega_item_id' => $item->id,
            'tipo' => 'entrega_cliente',
            'modo' => 'manter_em_entrega',
            'quantidade' => 1,
            'motivo' => 'Confirmação de entrega incorreta.',
            'idempotency_key' => 'estorno-entrega-manter',
        ])->assertOk()
            ->assertJsonPath('data.item.quantidade_entregue', 1)
            ->assertJsonPath('data.item.quantidade_expedida', 2);

        $this->assertSame(0, (int) Estoque::where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
    }

    public function test_estorno_de_entrega_pode_devolver_parcialmente_ao_deposito_original(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedido(2, Pedido::TIPO_VENDA, Pedido::ORIGEM_ABASTECIMENTO_ESTOQUE);
        Sanctum::actingAs($usuario);
        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 2]
        );
        $fluxo = app(EntregaProdutoService::class);
        $item = $fluxo->criarDemandaPedido($pedido, $usuario->id, true)->firstOrFail();
        $item = $fluxo->expedirItem($item, $deposito->id, 2, $usuario->id, idempotencyKey: 'expedir-retorno');
        $fluxo->entregarItem($item, 2, $usuario->id, idempotencyKey: 'entregar-retorno');

        $this->postJson("/api/v1/pedidos/{$pedido->id}/estornos-operacionais", [
            'produto_entrega_item_id' => $item->id,
            'tipo' => 'entrega_cliente',
            'modo' => 'devolver_estoque',
            'quantidade' => 1,
            'motivo' => 'Produto retornou fisicamente.',
            'idempotency_key' => 'estorno-entrega-retorno',
        ])->assertOk()
            ->assertJsonPath('data.item.quantidade_entregue', 1)
            ->assertJsonPath('data.item.quantidade_expedida', 1);

        $this->assertSame(1, (int) Estoque::where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
    }

    public function test_bloqueia_devolucao_ao_estoque_quando_entrega_nao_teve_saida_fisica(): void
    {
        [$usuario, $pedido] = $this->criarPedido(1, Pedido::TIPO_VENDA, Pedido::ORIGEM_ABASTECIMENTO_ESTOQUE);
        Sanctum::actingAs($usuario);
        $fluxo = app(EntregaProdutoService::class);
        $item = $fluxo->criarDemandaPedido($pedido, $usuario->id, false)->firstOrFail();
        $fluxo->entregarItem($item, 1, $usuario->id, idempotencyKey: 'nota:entregar-sem-saldo', permitirSemExpedicao: true);

        $this->getJson("/api/v1/pedidos/{$pedido->id}/estornos-operacionais")
            ->assertOk()
            ->assertJsonPath('data.itens.0.entrega.modos.devolver_estoque.bloqueado', true);

        $this->postJson("/api/v1/pedidos/{$pedido->id}/estornos-operacionais", [
            'produto_entrega_item_id' => $item->id,
            'tipo' => 'entrega_cliente',
            'modo' => 'devolver_estoque',
            'quantidade' => 1,
            'motivo' => 'Tentativa inválida.',
            'idempotency_key' => 'estorno-sem-saida',
        ])->assertUnprocessable();
    }

    public function test_exige_permissao_de_movimentacao(): void
    {
        [$usuario, $pedido] = $this->criarPedido(1, Pedido::TIPO_REPOSICAO, Pedido::ORIGEM_ABASTECIMENTO_FABRICA, false);
        Sanctum::actingAs($usuario);

        $this->getJson("/api/v1/pedidos/{$pedido->id}/estornos-operacionais")->assertForbidden();
    }

    private function criarPedido(int $quantidade, string $tipo, string $origem, bool $comPermissao = true): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuário Estorno Operacional',
            'email' => uniqid('estorno-', true).'@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        Cache::put('permissoes_usuario_'.$usuario->id, $comPermissao ? ['estoque.movimentar'] : [], now()->addHour());
        $cliente = Cliente::create([
            'nome' => 'Cliente Estorno Operacional',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);
        $categoria = Categoria::create(['nome' => 'Categoria Estorno']);
        $produto = Produto::create(['nome' => 'Produto Estorno', 'id_categoria' => $categoria->id, 'ativo' => true]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => uniqid('EST-', false),
            'nome' => 'Variação Estorno',
            'preco' => 100,
            'custo' => 50,
        ]);
        $deposito = Deposito::create(['nome' => 'Depósito Estorno']);
        $pedido = Pedido::create([
            'tipo' => $tipo,
            'origem_abastecimento' => $origem,
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
