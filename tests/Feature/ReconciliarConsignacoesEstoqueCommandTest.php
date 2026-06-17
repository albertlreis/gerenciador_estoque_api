<?php

namespace Tests\Feature;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Consignacao;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueReserva;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Produto;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ReconciliarConsignacoesEstoqueCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_nao_persiste_correcoes(): void
    {
        $consignacao = $this->criarConsignacao(quantidade: 2, estoque: 2);

        $this->artisan('consignacoes:reconciliar-estoque')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('produto_entrega_itens', [
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_CONSIGNACAO,
            'consignacao_id' => $consignacao->id,
        ]);
        $this->assertSame(0, EstoqueReserva::query()->count());
    }

    public function test_execute_cria_demanda_e_reserva_para_pendente_com_saldo(): void
    {
        $consignacao = $this->criarConsignacao(quantidade: 2, estoque: 3);

        $this->artisan('consignacoes:reconciliar-estoque --execute')
            ->assertExitCode(0);

        $this->assertDatabaseHas('produto_entrega_itens', [
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_CONSIGNACAO,
            'consignacao_id' => $consignacao->id,
            'quantidade_total' => 2,
            'quantidade_reservada' => 2,
            'status' => ProdutoEntregaItem::STATUS_RESERVADO,
        ]);
        $this->assertDatabaseHas('estoque_reservas', [
            'pedido_id' => $consignacao->pedido_id,
            'pedido_item_id' => $consignacao->pedido_item_id,
            'id_variacao' => $consignacao->produto_variacao_id,
            'id_deposito' => $consignacao->deposito_id,
            'quantidade' => 2,
            'status' => 'ativa',
        ]);
        $this->assertDatabaseMissing('estoque_movimentacoes', [
            'ref_type' => 'consignacao',
            'ref_id' => $consignacao->id,
            'tipo' => EstoqueMovimentacaoTipo::CONSIGNACAO_ENVIO->value,
        ]);
    }

    public function test_execute_adota_reserva_existente_sem_duplicar(): void
    {
        $consignacao = $this->criarConsignacao(quantidade: 2, estoque: 3);
        $entrega = ProdutoEntregaItem::query()->create([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_CONSIGNACAO,
            'origem_id' => $consignacao->id,
            'pedido_id' => $consignacao->pedido_id,
            'pedido_item_id' => $consignacao->pedido_item_id,
            'consignacao_id' => $consignacao->id,
            'id_variacao' => $consignacao->produto_variacao_id,
            'quantidade_total' => 2,
            'id_deposito_origem' => $consignacao->deposito_id,
            'status' => ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
        ]);
        $reserva = EstoqueReserva::query()->create([
            'id_variacao' => $consignacao->produto_variacao_id,
            'id_deposito' => $consignacao->deposito_id,
            'pedido_id' => $consignacao->pedido_id,
            'pedido_item_id' => $consignacao->pedido_item_id,
            'quantidade' => 2,
            'status' => 'ativa',
            'motivo' => 'produto_entrega',
        ]);

        $this->artisan('consignacoes:reconciliar-estoque --execute')
            ->assertExitCode(0);

        $this->assertSame(1, EstoqueReserva::query()
            ->where('pedido_id', $consignacao->pedido_id)
            ->where('pedido_item_id', $consignacao->pedido_item_id)
            ->where('id_variacao', $consignacao->produto_variacao_id)
            ->where('id_deposito', $consignacao->deposito_id)
            ->count());
        $entrega->refresh();
        $this->assertSame(2, (int) $entrega->quantidade_reservada);
        $this->assertSame(ProdutoEntregaItem::STATUS_RESERVADO, $entrega->status);
        $this->assertDatabaseHas('produto_entrega_eventos', [
            'produto_entrega_item_id' => $entrega->id,
            'tipo_evento' => ProdutoEntregaEvento::RESERVA_CRIADA,
            'estoque_reserva_id' => $reserva->id,
        ]);
    }

    public function test_execute_nao_recria_reserva_para_pendente_ja_enviada(): void
    {
        $consignacao = $this->criarConsignacao(quantidade: 1, estoque: 0);
        EstoqueMovimentacao::query()->create([
            'id_variacao' => $consignacao->produto_variacao_id,
            'id_deposito_origem' => $consignacao->deposito_id,
            'tipo' => EstoqueMovimentacaoTipo::CONSIGNACAO_ENVIO->value,
            'quantidade' => 1,
            'data_movimentacao' => now(),
            'ref_type' => 'consignacao',
            'ref_id' => $consignacao->id,
            'pedido_id' => $consignacao->pedido_id,
            'pedido_item_id' => $consignacao->pedido_item_id,
        ]);

        $this->artisan('consignacoes:reconciliar-estoque --execute')
            ->assertExitCode(0);

        $this->assertSame(0, EstoqueReserva::query()
            ->where('pedido_id', $consignacao->pedido_id)
            ->where('pedido_item_id', $consignacao->pedido_item_id)
            ->where('id_variacao', $consignacao->produto_variacao_id)
            ->where('id_deposito', $consignacao->deposito_id)
            ->count());
        $entrega = ProdutoEntregaItem::query()->where('consignacao_id', $consignacao->id)->firstOrFail();
        $this->assertSame(1, (int) $entrega->quantidade_expedida);
        $this->assertSame(ProdutoEntregaItem::STATUS_RESERVADO, $entrega->status);
    }

    public function test_execute_cancela_reserva_remanescente_de_consignacao_devolvida(): void
    {
        $consignacao = $this->criarConsignacao(status: 'devolvido', quantidade: 1, estoque: 1);
        $reserva = EstoqueReserva::query()->create([
            'id_variacao' => $consignacao->produto_variacao_id,
            'id_deposito' => $consignacao->deposito_id,
            'pedido_id' => $consignacao->pedido_id,
            'pedido_item_id' => $consignacao->pedido_item_id,
            'quantidade' => 1,
            'status' => 'ativa',
            'motivo' => 'produto_entrega',
        ]);

        $this->artisan('consignacoes:reconciliar-estoque --execute')
            ->assertExitCode(0);

        $reserva->refresh();
        $this->assertSame('cancelada', $reserva->status);
        $this->assertSame('reconciliacao_consignacao_finalizada', $reserva->motivo);
    }

    public function test_relata_devolucao_orfa_sem_alterar_movimentacao(): void
    {
        $this->criarConsignacao(status: 'devolvido', quantidade: 1, estoque: 1);
        $movimentacao = EstoqueMovimentacao::query()->create([
            'id_variacao' => ProdutoVariacao::query()->value('id'),
            'id_deposito_destino' => Deposito::query()->value('id'),
            'tipo' => EstoqueMovimentacaoTipo::CONSIGNACAO_DEVOLUCAO->value,
            'quantidade' => 1,
            'data_movimentacao' => now(),
        ]);

        Artisan::call('consignacoes:reconciliar-estoque');

        $this->assertStringContainsString('devolucoes_orfas', Artisan::output());
        $this->assertDatabaseHas('estoque_movimentacoes', [
            'id' => $movimentacao->id,
            'ref_type' => null,
            'ref_id' => null,
        ]);
    }

    private function criarConsignacao(string $status = 'pendente', int $quantidade = 1, int $estoque = 0): Consignacao
    {
        $usuario = Usuario::query()->create([
            'nome' => 'Usuario Reconciliacao',
            'email' => uniqid('reconciliacao-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        $cliente = Cliente::query()->create([
            'nome' => 'Cliente Reconciliacao',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);
        $categoria = Categoria::query()->create(['nome' => 'Categoria Reconciliacao ' . uniqid()]);
        $produto = Produto::query()->create([
            'nome' => 'Produto Reconciliacao',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::query()->create([
            'produto_id' => $produto->id,
            'referencia' => 'REC-' . uniqid(),
            'nome' => 'Variacao Reconciliacao',
            'preco' => 100,
            'custo' => 60,
        ]);
        $deposito = Deposito::query()->create(['nome' => 'Deposito Reconciliacao ' . uniqid()]);
        Estoque::query()->updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => $estoque]
        );
        $pedido = Pedido::query()->create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => $quantidade * 100,
            'prazo_dias_uteis' => 15,
        ]);
        $pedidoItem = PedidoItem::query()->create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => $quantidade,
            'preco_unitario' => 100,
            'subtotal' => $quantidade * 100,
        ]);

        return Consignacao::query()->create([
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $pedidoItem->id,
            'produto_variacao_id' => $variacao->id,
            'deposito_id' => $deposito->id,
            'quantidade' => $quantidade,
            'data_envio' => now()->toDateString(),
            'prazo_resposta' => now()->addDays(7)->toDateString(),
            'status' => $status,
        ]);
    }
}
