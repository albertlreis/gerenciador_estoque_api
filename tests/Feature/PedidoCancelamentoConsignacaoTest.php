<?php

namespace Tests\Feature;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Consignacao;
use App\Models\ConsignacaoDevolucao;
use App\Models\Deposito;
use App\Models\EstoqueMovimentacao;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use App\Services\PedidoCancelamentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PedidoCancelamentoConsignacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_bloqueia_cancelamento_com_consignacao_ainda_no_cliente(): void
    {
        [$usuario, $pedido] = $this->cenarioConsignacao(false);

        $this->expectException(ValidationException::class);
        app(PedidoCancelamentoService::class)->cancelar($pedido, $this->opcoes(), $usuario->id);
    }

    public function test_cancelamento_apos_devolucao_integral_nao_estorna_envio_da_consignacao(): void
    {
        [$usuario, $pedido, $consignacao, $envio] = $this->cenarioConsignacao(true);

        app(PedidoCancelamentoService::class)->cancelar($pedido, $this->opcoes(), $usuario->id);

        $this->assertDatabaseMissing('estoque_movimentacoes', [
            'tipo' => EstoqueMovimentacaoTipo::ESTORNO->value,
            'ref_type' => 'estorno',
            'ref_id' => $envio->id,
        ]);
        $this->assertSame(PedidoStatus::CANCELADO->value, $pedido->fresh('statusAtual')->statusAtual->getRawOriginal('status'));
    }

    /** @return array{Usuario,Pedido,Consignacao,EstoqueMovimentacao} */
    private function cenarioConsignacao(bool $devolvida): array
    {
        $usuario = Usuario::create(['nome' => 'Operador', 'email' => uniqid().'@test.com', 'senha' => 'senha', 'ativo' => true]);
        $categoria = Categoria::create(['nome' => uniqid('Cat ', true)]);
        $produto = Produto::create(['nome' => 'Produto consignado', 'id_categoria' => $categoria->id, 'ativo' => true]);
        $variacao = ProdutoVariacao::create(['produto_id' => $produto->id, 'referencia' => uniqid('CONS-'), 'nome' => 'Única', 'preco' => 10, 'custo' => 5]);
        $deposito = Deposito::create(['nome' => uniqid('Dep ', true)]);
        $pedido = Pedido::create(['tipo' => Pedido::TIPO_VENDA, 'id_usuario' => $usuario->id, 'data_pedido' => now(), 'valor_total' => 10]);
        PedidoStatusHistorico::create(['pedido_id' => $pedido->id, 'status' => PedidoStatus::CONSIGNADO, 'data_status' => now(), 'usuario_id' => $usuario->id]);
        $item = PedidoItem::create(['id_pedido' => $pedido->id, 'id_variacao' => $variacao->id, 'id_deposito' => $deposito->id, 'quantidade' => 1, 'preco_unitario' => 10, 'subtotal' => 10]);
        $consignacao = Consignacao::create(['pedido_id' => $pedido->id, 'pedido_item_id' => $item->id, 'produto_variacao_id' => $variacao->id, 'deposito_id' => $deposito->id, 'quantidade' => 1, 'data_envio' => now(), 'prazo_resposta' => now()->addWeek(), 'status' => $devolvida ? 'devolvido' : 'pendente']);
        ProdutoEntregaItem::create([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_CONSIGNACAO,
            'origem_id' => $consignacao->id,
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $item->id,
            'consignacao_id' => $consignacao->id,
            'id_variacao' => $variacao->id,
            'quantidade_total' => 1,
            'quantidade_expedida' => 1,
            'id_deposito_origem' => $deposito->id,
            'status' => ProdutoEntregaItem::STATUS_RESERVADO,
        ]);
        $envio = EstoqueMovimentacao::create([
            'id_variacao' => $variacao->id,
            'id_deposito_origem' => $deposito->id,
            'tipo' => EstoqueMovimentacaoTipo::CONSIGNACAO_ENVIO->value,
            'quantidade' => 1,
            'data_movimentacao' => now(),
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $item->id,
            'ref_type' => 'consignacao',
            'ref_id' => $consignacao->id,
        ]);
        if ($devolvida) {
            ConsignacaoDevolucao::create([
                'consignacao_id' => $consignacao->id,
                'usuario_id' => $usuario->id,
                'deposito_id' => $deposito->id,
                'quantidade' => 1,
                'data_devolucao' => now(),
            ]);
        }

        return [$usuario, $pedido, $consignacao, $envio];
    }

    /** @return array<string,mixed> */
    private function opcoes(): array
    {
        return [
            'cancelar_reservas' => true,
            'estornar_estoque' => true,
            'cancelar_financeiro' => false,
            'observacoes' => 'Cancelamento controlado de teste.',
        ];
    }
}
