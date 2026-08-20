<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidoItemStatusHistoricoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 8, 20, 10, 0, 0, config('app.timezone', 'America/Belem')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_registra_embarque_somente_para_saldo_pendente_sem_regredir_pedido_ou_estoque(): void
    {
        [$usuario, $pedido, $item, $demanda] = $this->criarPedidoComItem(5, 3);
        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::ENVIO_CLIENTE,
            'data_status' => now()->subDay(),
            'usuario_id' => $usuario->id,
        ]);

        $this->getJson("/api/v1/pedidos/{$pedido->id}/status/itens?status=embarque_fabrica")
            ->assertOk()
            ->assertJsonPath('0.pedido_item_id', $item->id)
            ->assertJsonPath('0.quantidade_total', 5)
            ->assertJsonPath('0.quantidade_recebida', 3)
            ->assertJsonPath('0.quantidade_elegivel', 2);

        $this->postJson("/api/v1/pedidos/{$pedido->id}/status/itens", [
            'status' => 'embarque_fabrica',
            'data_status' => '2026-08-18',
            'data_prevista' => '2026-08-25',
            'observacoes' => 'Saldo pendente embarcado.',
            'itens' => [['pedido_item_id' => $item->id, 'quantidade' => 2]],
        ])->assertCreated()->assertJsonPath('marco_global_criado', false);

        $this->assertDatabaseHas('pedido_item_status_historico', [
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $item->id,
            'status' => 'embarque_fabrica',
            'quantidade' => 2,
            'data_prevista' => '2026-08-25',
        ]);
        $this->assertSame('envio_cliente', $pedido->fresh()->statusAtual->getRawOriginal('status'));
        $this->assertSame(3, (int) $demanda->fresh()->quantidade_recebida);
        $this->assertSame(0, (int) $demanda->fresh()->quantidade_expedida);
        $this->getJson("/api/v1/pedidos/{$pedido->id}/status/itens?status=embarque_fabrica")
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_rejeita_item_de_outro_pedido_quantidade_excedente_duplicidade_e_datas_invalidas(): void
    {
        [, $pedido, $item] = $this->criarPedidoComItem(5, 2);
        [, $outroPedido, $outroItem] = $this->criarPedidoComItem(1, 0, false);
        $base = [
            'status' => 'nota_emitida',
            'data_status' => '2026-08-18',
            'itens' => [['pedido_item_id' => $item->id, 'quantidade' => 1]],
        ];

        $this->postJson("/api/v1/pedidos/{$pedido->id}/status/itens", [
            ...$base,
            'itens' => [['pedido_item_id' => $outroItem->id, 'quantidade' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['itens']);

        $this->postJson("/api/v1/pedidos/{$pedido->id}/status/itens", [
            ...$base,
            'itens' => [['pedido_item_id' => $item->id, 'quantidade' => 4]],
        ])->assertStatus(422)->assertJsonValidationErrors(['itens']);

        $this->postJson("/api/v1/pedidos/{$pedido->id}/status/itens", [
            ...$base,
            'itens' => [
                ['pedido_item_id' => $item->id, 'quantidade' => 1],
                ['pedido_item_id' => $item->id, 'quantidade' => 1],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['itens.1.pedido_item_id']);

        $this->postJson("/api/v1/pedidos/{$pedido->id}/status/itens", [
            ...$base,
            'data_status' => '2026-08-21',
        ])->assertStatus(422)->assertJsonValidationErrors(['data_status']);

        $this->postJson("/api/v1/pedidos/{$pedido->id}/status/itens", [
            ...$base,
            'data_status' => '2026-07-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['data_status']);

        $this->postJson("/api/v1/pedidos/{$pedido->id}/status/itens", [
            'status' => 'pedido_enviado_fabrica',
            'data_status' => '2026-08-18',
            'itens' => [['pedido_item_id' => $item->id, 'quantidade' => 1]],
        ])->assertCreated();

        $this->postJson("/api/v1/pedidos/{$pedido->id}/status/itens", [
            ...$base,
            'data_status' => '2026-08-17',
        ])->assertStatus(422)->assertJsonValidationErrors(['data_status']);

        $this->assertNotSame($pedido->id, $outroPedido->id);
    }

    public function test_fluxo_de_reposicao_vai_da_fabrica_ao_estoque_sem_etapas_de_cliente(): void
    {
        [, $pedido] = $this->criarPedidoComItem(2, 0, true, Pedido::TIPO_REPOSICAO);

        $valores = collect($this->getJson("/api/v1/pedidos/{$pedido->id}/status/opcoes")
            ->assertOk()
            ->json())->pluck('value')->all();

        $this->assertContains('pedido_enviado_fabrica', $valores);
        $this->assertContains('embarque_fabrica', $valores);
        $this->assertContains('entrega_estoque', $valores);
        $this->assertNotContains('envio_cliente', $valores);
        $this->assertNotContains('entrega_cliente', $valores);
    }

    public function test_cria_marco_global_quando_todos_os_itens_concluem_etapa_sem_status_posterior(): void
    {
        [, $pedido, $item] = $this->criarPedidoComItem(2, 0);

        $this->postJson("/api/v1/pedidos/{$pedido->id}/status/itens", [
            'status' => 'nota_emitida',
            'data_status' => '2026-08-18',
            'itens' => [['pedido_item_id' => $item->id, 'quantidade' => 2]],
        ])->assertCreated()->assertJsonPath('marco_global_criado', true);

        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => 'nota_emitida',
        ]);
    }

    private function criarPedidoComItem(
        int $quantidade,
        int $recebida,
        bool $autenticar = true,
        string $tipo = Pedido::TIPO_VENDA,
    ): array {
        $usuario = Usuario::create([
            'nome' => 'Usuario Status Item',
            'email' => uniqid('status-item-', true).'@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        Cache::put('permissoes_usuario_'.$usuario->id, ['pedidos.editar'], now()->addHour());
        if ($autenticar) {
            Sanctum::actingAs($usuario);
        }

        $cliente = Cliente::create([
            'nome' => 'Cliente Status Item',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);
        $categoria = Categoria::create(['nome' => 'Categoria '.uniqid()]);
        $produto = Produto::create(['nome' => 'Produto Status Item', 'id_categoria' => $categoria->id, 'ativo' => true]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => uniqid('STI-'),
            'nome' => 'Variacao Status Item',
            'preco' => 100,
            'custo' => 50,
        ]);
        $pedido = Pedido::create([
            'tipo' => $tipo,
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now()->subMonth(),
            'valor_total' => $quantidade * 100,
        ]);
        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::PEDIDO_CRIADO,
            'data_status' => now()->subMonth(),
            'usuario_id' => $usuario->id,
        ]);
        $item = PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacao->id,
            'quantidade' => $quantidade,
            'preco_unitario' => 100,
            'subtotal' => $quantidade * 100,
        ]);
        $demanda = ProdutoEntregaItem::create([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
            'origem_id' => $item->id,
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $item->id,
            'id_variacao' => $variacao->id,
            'quantidade_total' => $quantidade,
            'quantidade_recebida' => $recebida,
            'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
        ]);

        return [$usuario, $pedido, $item, $demanda];
    }
}
