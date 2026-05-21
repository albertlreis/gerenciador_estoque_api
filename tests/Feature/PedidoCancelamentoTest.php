<?php

namespace Tests\Feature;

use App\Enums\ContaStatus;
use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\ContaFinanceira;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueReserva;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use App\Services\EstoqueMovimentacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidoCancelamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancela_venda_com_reservas_estoque_e_financeiro(): void
    {
        [$usuario, $pedido, $variacao, $deposito] = $this->criarPedidoBase();

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 10]
        );

        $item = PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => 2,
            'preco_unitario' => 100,
            'subtotal' => 200,
        ]);

        EstoqueReserva::create([
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $item->id,
            'id_usuario' => $usuario->id,
            'quantidade' => 3,
            'status' => 'ativa',
        ]);

        app(EstoqueMovimentacaoService::class)->registrarSaidaPedido(
            variacaoId: $variacao->id,
            depositoSaidaId: $deposito->id,
            quantidade: 2,
            usuarioId: $usuario->id,
            observacao: "Pedido #{$pedido->id}",
            pedidoId: $pedido->id,
            pedidoItemId: $item->id,
        );

        $conta = ContaReceber::create([
            'pedido_id' => $pedido->id,
            'descricao' => 'Recebimento teste',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDay()->toDateString(),
            'valor_bruto' => 200,
            'valor_liquido' => 200,
            'saldo_aberto' => 200,
            'status' => ContaStatus::ABERTA,
        ]);

        $this->assertSame(8, (int) Estoque::where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));

        $response = $this->patchJson("/api/v1/pedidos/{$pedido->id}/cancelar", [
            'cancelar_reservas' => true,
            'estornar_estoque' => true,
            'cancelar_financeiro' => true,
            'observacoes' => 'Cliente desistiu',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::CANCELADO->value,
        ]);
        $this->assertDatabaseHas('estoque_reservas', [
            'pedido_id' => $pedido->id,
            'status' => 'cancelada',
        ]);
        $this->assertSame(10, (int) Estoque::where('id_variacao', $variacao->id)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertDatabaseHas('estoque_movimentacoes', [
            'tipo' => 'estorno',
            'id_deposito_destino' => $deposito->id,
            'quantidade' => 2,
        ]);
        $this->assertSame(ContaStatus::CANCELADA, $conta->fresh()->status);
    }

    public function test_bloqueia_cancelamento_financeiro_quando_ha_pagamento(): void
    {
        [$usuario, $pedido] = $this->criarPedidoBase();

        $conta = ContaReceber::create([
            'pedido_id' => $pedido->id,
            'descricao' => 'Recebimento teste',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDay()->toDateString(),
            'valor_bruto' => 200,
            'valor_liquido' => 200,
            'saldo_aberto' => 200,
            'status' => ContaStatus::ABERTA,
        ]);

        ContaReceberPagamento::create([
            'conta_receber_id' => $conta->id,
            'data_pagamento' => now()->toDateString(),
            'valor' => 50,
            'forma_pagamento' => 'PIX',
            'usuario_id' => $usuario->id,
            'conta_financeira_id' => ContaFinanceira::create(['nome' => 'Caixa Teste'])->id,
        ]);

        $response = $this->patchJson("/api/v1/pedidos/{$pedido->id}/cancelar", [
            'cancelar_financeiro' => true,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::CANCELADO->value,
        ]);
    }

    private function criarPedidoBase(): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Cancelamento',
            'email' => uniqid('cancelamento-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['pedidos.editar']);

        $cliente = Cliente::create([
            'nome' => 'Cliente Cancelamento',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);
        $categoria = Categoria::create(['nome' => 'Categoria Cancelamento']);
        $produto = Produto::create([
            'nome' => 'Produto Cancelamento',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => uniqid('CAN-', false),
            'nome' => 'Variacao Cancelamento',
            'preco' => 100,
            'custo' => 50,
        ]);
        $deposito = Deposito::create(['nome' => 'Deposito Cancelamento']);
        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 200,
        ]);
        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::PEDIDO_CRIADO,
            'data_status' => now(),
            'usuario_id' => $usuario->id,
        ]);

        return [$usuario, $pedido, $variacao, $deposito];
    }
}
