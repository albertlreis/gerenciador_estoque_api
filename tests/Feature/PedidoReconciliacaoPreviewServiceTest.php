<?php

namespace Tests\Feature;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\PedidoStatus;
use App\Models\Categoria;
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
use App\Services\PedidoReconciliacaoPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PedidoReconciliacaoPreviewServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_do_pedido_externo_mantem_devolucao_separada_e_projeta_estorno_sem_escrever(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Reconciliacao',
            'email' => 'reconciliacao-preview@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        $categoria = Categoria::create(['nome' => 'Categoria Reconciliacao']);
        $produto = Produto::create([
            'nome' => 'Penteadeira Monza',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'A1270',
            'nome' => 'Madeira AC03',
            'preco' => 100,
            'custo' => 50,
        ]);
        $variacaoDevolucao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'A1052',
            'nome' => 'Item devolvido',
            'preco' => 80,
            'custo' => 40,
        ]);
        $deposito = Deposito::create(['nome' => 'Deposito Principal']);
        $pedido = Pedido::create([
            'tipo' => Pedido::TIPO_VENDA,
            'origem_abastecimento' => Pedido::ORIGEM_ABASTECIMENTO_FABRICA,
            'id_usuario' => $usuario->id,
            'numero_externo' => '20009',
            'data_pedido' => now(),
            'valor_total' => 100,
        ]);
        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::ENTREGA_ESTOQUE,
            'data_status' => now(),
            'usuario_id' => $usuario->id,
        ]);
        $pedidoItem = PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => 1,
            'preco_unitario' => 100,
            'subtotal' => 100,
        ]);
        $item = ProdutoEntregaItem::create([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
            'origem_id' => $pedido->id,
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $pedidoItem->id,
            'id_variacao' => $variacao->id,
            'quantidade_total' => 1,
            'quantidade_recebida' => 1,
            'quantidade_reservada' => 1,
            'quantidade_expedida' => 1,
            'quantidade_entregue' => 1,
            'id_deposito_origem' => $deposito->id,
            'id_deposito_destino' => $deposito->id,
            'status' => ProdutoEntregaItem::STATUS_ENTREGUE,
        ]);
        $devolucao = ProdutoEntregaItem::create([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_DEVOLUCAO,
            'origem_id' => $pedido->id,
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $pedidoItem->id,
            'id_variacao' => $variacaoDevolucao->id,
            'quantidade_total' => 1,
            'quantidade_recebida' => 1,
            'quantidade_reservada' => 0,
            'quantidade_expedida' => 0,
            'quantidade_entregue' => 0,
            'id_deposito_destino' => $deposito->id,
            'status' => ProdutoEntregaItem::STATUS_RECEBIDO,
        ]);
        Estoque::updateOrCreate(
            [
                'id_variacao' => $variacao->id,
                'id_deposito' => $deposito->id,
            ],
            ['quantidade' => 0]
        );

        $movimento = new EstoqueMovimentacao([
            'id_variacao' => $variacao->id,
            'id_deposito_origem' => $deposito->id,
            'tipo' => EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value,
            'quantidade' => 1,
            'data_movimentacao' => now(),
            'id_usuario' => $usuario->id,
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $pedidoItem->id,
        ]);
        $movimento->id = 6570;
        $movimento->save();

        ProdutoEntregaEvento::create([
            'produto_entrega_item_id' => $item->id,
            'tipo_evento' => ProdutoEntregaEvento::EXPEDIDO_CLIENTE,
            'ocorrido_em' => now(),
            'quantidade' => 1,
            'id_deposito_origem' => $deposito->id,
            'estoque_movimentacao_id' => $movimento->id,
            'usuario_id' => $usuario->id,
            'idempotency_key' => 'reconciliacao-preview-expedido',
        ]);
        ProdutoEntregaEvento::create([
            'produto_entrega_item_id' => $item->id,
            'tipo_evento' => ProdutoEntregaEvento::ENTREGUE_CLIENTE,
            'ocorrido_em' => now(),
            'quantidade' => 1,
            'usuario_id' => $usuario->id,
            'idempotency_key' => 'reconciliacao-preview-entregue',
        ]);
        ProdutoEntregaEvento::create([
            'produto_entrega_item_id' => $devolucao->id,
            'tipo_evento' => ProdutoEntregaEvento::DEVOLUCAO_RECEBIDA,
            'ocorrido_em' => now(),
            'quantidade' => 1,
            'id_deposito_destino' => $deposito->id,
            'usuario_id' => $usuario->id,
            'idempotency_key' => 'reconciliacao-preview-devolucao',
        ]);

        $contagensAntes = [
            EstoqueMovimentacao::query()->count(),
            ProdutoEntregaEvento::query()->count(),
            (int) Estoque::query()->sum('quantidade'),
        ];

        $preview = app(PedidoReconciliacaoPreviewService::class)->previewPorIdentificador('20009');

        $this->assertTrue($preview['dry_run']);
        $this->assertSame(PedidoStatus::ENTREGA_ESTOQUE->value, $preview['status_fonte_verdade']['codigo']);
        $this->assertSame('Recebido no estoque — aguardando entrega ao cliente', $preview['status_fonte_verdade']['rotulo']);
        $this->assertTrue($preview['divergencia']);
        $this->assertNull($preview['saldo_fisico_informado']);
        $this->assertTrue($preview['exige_conferencia_fisica']);
        $this->assertCount(1, $preview['itens_canonicos']);
        $this->assertCount(2, $preview['eventos_conflitantes']);
        $this->assertCount(1, $preview['devolucoes']);
        $this->assertSame('A1052', $preview['devolucoes'][0]['referencia']);

        $movimentoPreview = collect($preview['movimentos_relacionados'])->firstWhere('id', 6570);
        $this->assertNotNull($movimentoPreview);
        $this->assertTrue($movimentoPreview['candidato_estorno']);
        $this->assertSame(0, $movimentoPreview['saldo_atual']);
        $this->assertSame(1, $movimentoPreview['saldo_resultante_preview']);
        $this->assertSame(6570, $preview['preview_estorno']['movimentos_candidatos'][0]['id']);
        $this->assertNull($preview['preview_estorno']['saldo_fisico_informado']);
        $this->assertTrue($preview['preview_estorno']['exige_conferencia_fisica']);
        $this->assertFalse($preview['preview_estorno']['aplicacao_automatica']);

        $this->assertSame($contagensAntes, [
            EstoqueMovimentacao::query()->count(),
            ProdutoEntregaEvento::query()->count(),
            (int) Estoque::query()->sum('quantidade'),
        ]);
    }
}
