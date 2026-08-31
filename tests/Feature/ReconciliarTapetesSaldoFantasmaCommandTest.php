<?php

namespace Tests\Feature;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Consignacao;
use App\Models\ConsignacaoDevolucao;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueReserva;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use App\Services\EstoqueMovimentacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ReconciliarTapetesSaldoFantasmaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_simula_aplica_e_nao_altera_status_dos_pedidos(): void
    {
        $cenario = $this->criarCenario();
        $reservaGatsby = $this->criarReservaGatsby($cenario);
        $historicoAntes = PedidoStatusHistorico::query()
            ->whereIn('pedido_id', [
                $cenario['reposicao']->id,
                $cenario['cancelado']->id,
                $reservaGatsby['pedido']->id,
            ])
            ->orderBy('id')
            ->get()
            ->toArray();

        $dryRunOutput = new BufferedOutput();
        $dryRunExitCode = Artisan::call(
            'estoque:reconciliar-tapetes-saldo-fantasma',
            [],
            $dryRunOutput
        );
        $dryRunText = $dryRunOutput->fetch();
        $this->assertSame(0, $dryRunExitCode, $dryRunText);
        $this->assertStringContainsString('dry-run', $dryRunText);

        $this->assertSame(15, $this->saldo($cenario['geometria'], $cenario['jb']));
        $this->assertSame(24, $this->saldo($cenario['gatsby'], $cenario['jb']));
        $this->assertSame(1, $this->saldo($cenario['organico'], $cenario['loja']));

        $commandOutput = new BufferedOutput();
        $exitCode = Artisan::call('estoque:reconciliar-tapetes-saldo-fantasma', [
            '--aplicar' => true,
            '--confirmacao' => '10665:10.9884-4',
        ], $commandOutput);
        $output = $commandOutput->fetch();
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Reconciliacao aplicada com sucesso', $output);

        $this->assertSame(1, $this->saldo($cenario['geometria'], $cenario['jb']));
        $this->assertSame(1, $this->saldo($cenario['gatsby'], $cenario['jb']));
        $this->assertSame(0, $this->saldo($cenario['organico'], $cenario['loja']));
        $this->assertDatabaseHas('estoque_reservas', [
            'id' => $reservaGatsby['reserva']->id,
            'id_variacao' => $cenario['gatsby']->id,
            'id_deposito' => $cenario['jb']->id,
            'quantidade' => 1,
            'quantidade_consumida' => 0,
            'status' => 'ativa',
        ]);
        $this->assertSame(1, (int) $cenario['entrega_geometria']->fresh()->quantidade_recebida);
        $this->assertSame(1, (int) $cenario['entrega_gatsby']->fresh()->quantidade_recebida);
        $this->assertDatabaseHas('estoque_movimentacoes', [
            'id_variacao' => $cenario['organico']->id,
            'id_deposito_origem' => $cenario['loja']->id,
            'tipo' => EstoqueMovimentacaoTipo::SAIDA->value,
            'quantidade' => 1,
            'ref_type' => 'correcao_saldo_fantasma',
            'ref_id' => $cenario['credito_duplicado']->id,
        ]);
        $this->assertDatabaseCount('pedido_status_historico', count($historicoAntes));
        $this->assertSame(
            $historicoAntes,
            PedidoStatusHistorico::query()
                ->whereIn('pedido_id', [
                    $cenario['reposicao']->id,
                    $cenario['cancelado']->id,
                    $reservaGatsby['pedido']->id,
                ])
                ->orderBy('id')
                ->get()
                ->toArray()
        );

        $movimentosDepois = DB::table('estoque_movimentacoes')->count();
        $secondRunOutput = new BufferedOutput();
        $secondRunExitCode = Artisan::call('estoque:reconciliar-tapetes-saldo-fantasma', [
            '--aplicar' => true,
            '--confirmacao' => '10665:10.9884-4',
        ], $secondRunOutput);
        $secondRunText = $secondRunOutput->fetch();
        $this->assertSame(0, $secondRunExitCode, $secondRunText);
        $this->assertStringContainsString('nenhuma alteracao e necessaria', $secondRunText);
        $this->assertSame($movimentosDepois, DB::table('estoque_movimentacoes')->count());

        $auditOutput = new BufferedOutput();
        $auditExitCode = Artisan::call('pedidos:auditar-fluxo', [
            '--pedido' => '10665',
            '--json' => true,
        ], $auditOutput);
        $auditText = $auditOutput->fetch();
        $this->assertSame(0, $auditExitCode, $auditText);
        $this->assertStringContainsString('"total": 0', $auditText);
    }

    public function test_bloqueia_e_nao_altera_nada_quando_reserva_ativa_excede_saldo_final(): void
    {
        $cenario = $this->criarCenario();
        EstoqueReserva::create([
            'id_variacao' => $cenario['gatsby']->id,
            'id_deposito' => $cenario['jb']->id,
            'pedido_id' => $cenario['reposicao']->id,
            'pedido_item_id' => $cenario['item_gatsby']->id,
            'quantidade' => 2,
            'quantidade_consumida' => 0,
            'status' => 'ativa',
            'motivo' => 'teste_concorrencia',
        ]);

        $movimentosAntes = DB::table('estoque_movimentacoes')->count();
        $eventosAntes = ProdutoEntregaEvento::query()->count();

        $this->artisan('estoque:reconciliar-tapetes-saldo-fantasma', [
            '--aplicar' => true,
            '--confirmacao' => '10665:10.9884-4',
        ])->expectsOutputToContain('reconciliacao foi bloqueada')
            ->assertExitCode(1);

        $this->assertSame(15, $this->saldo($cenario['geometria'], $cenario['jb']));
        $this->assertSame(24, $this->saldo($cenario['gatsby'], $cenario['jb']));
        $this->assertSame(1, $this->saldo($cenario['organico'], $cenario['loja']));
        $this->assertSame($movimentosAntes, DB::table('estoque_movimentacoes')->count());
        $this->assertSame($eventosAntes, ProdutoEntregaEvento::query()->count());
    }

    /** @param array<string,mixed> $cenario
     * @return array{pedido:Pedido,reserva:EstoqueReserva}
     */
    private function criarReservaGatsby(array $cenario): array
    {
        $pedido = Pedido::create([
            'tipo' => Pedido::TIPO_VENDA,
            'id_usuario' => $cenario['usuario']->id,
            'data_pedido' => now(),
            'valor_total' => 1,
        ]);
        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::CONSIGNADO,
            'data_status' => now(),
        ]);
        $item = PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $cenario['gatsby']->id,
            'id_deposito' => $cenario['jb']->id,
            'quantidade' => 1,
            'preco_unitario' => 1,
            'subtotal' => 1,
        ]);
        $reserva = EstoqueReserva::create([
            'id_variacao' => $cenario['gatsby']->id,
            'id_deposito' => $cenario['jb']->id,
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $item->id,
            'quantidade' => 1,
            'quantidade_consumida' => 0,
            'status' => 'ativa',
            'motivo' => 'produto_entrega',
        ]);

        return compact('pedido', 'reserva');
    }

    /** @return array<string,mixed> */
    private function criarCenario(): array
    {
        $categoria = Categoria::create(['nome' => 'Tapetes reconciliacao']);
        $produto = Produto::create(['nome' => 'Tapetes reconciliacao', 'id_categoria' => $categoria->id, 'ativo' => true]);
        $jb = Deposito::create(['nome' => 'Depósito JB']);
        $loja = Deposito::create(['nome' => 'Loja']);
        $usuario = Usuario::create([
            'nome' => 'Usuario reconciliacao',
            'email' => 'reconciliacao-tapetes@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        $reposicao = Pedido::create([
            'tipo' => Pedido::TIPO_REPOSICAO,
            'numero_externo' => '10665',
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 2,
        ]);
        PedidoStatusHistorico::create([
            'pedido_id' => $reposicao->id,
            'status' => PedidoStatus::PEDIDO_CRIADO,
            'data_status' => now()->subDay(),
        ]);
        PedidoStatusHistorico::create([
            'pedido_id' => $reposicao->id,
            'status' => PedidoStatus::FINALIZADO,
            'data_status' => now(),
        ]);

        [$geometria, $itemGeometria, $entregaGeometria] = $this->criarRecebimentoCorrompido(
            $produto,
            $reposicao,
            $jb,
            '11.10445-5',
            15
        );
        [$gatsby, $itemGatsby, $entregaGatsby] = $this->criarRecebimentoCorrompido(
            $produto,
            $reposicao,
            $jb,
            '11.10054-4',
            24
        );

        $organico = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => '10.9884-4',
            'nome' => 'NUDE BLOSSON NACAR MOCHA 250X300',
            'preco' => 1,
            'custo' => 1,
        ]);
        ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => '10.9884-4',
            'nome' => 'WINTER NEW STONE SILVER BELUGA 300X400',
            'preco' => 1,
            'custo' => 1,
        ]);
        $cancelado = Pedido::create([
            'tipo' => Pedido::TIPO_VENDA,
            'numero_externo' => '69',
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 1,
        ]);
        PedidoStatusHistorico::create([
            'pedido_id' => $cancelado->id,
            'status' => PedidoStatus::CANCELADO,
            'data_status' => now(),
        ]);
        $consignacao = Consignacao::create([
            'pedido_id' => $cancelado->id,
            'produto_variacao_id' => $organico->id,
            'deposito_id' => $loja->id,
            'quantidade' => 1,
            'data_envio' => now()->toDateString(),
            'prazo_resposta' => now()->addDays(3)->toDateString(),
            'status' => 'devolvido',
        ]);

        $movimentos = app(EstoqueMovimentacaoService::class);
        $movimentos->registrarMovimentacaoManual([
            'id_variacao' => $organico->id,
            'id_deposito_destino' => $loja->id,
            'tipo' => EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
            'quantidade' => 1,
            'observacao' => 'Entrada historica.',
        ]);
        $envio = $movimentos->registrarMovimentacaoManual([
            'id_variacao' => $organico->id,
            'id_deposito_origem' => $loja->id,
            'tipo' => EstoqueMovimentacaoTipo::CONSIGNACAO_ENVIO->value,
            'quantidade' => 1,
            'pedido_id' => $cancelado->id,
            'ref_type' => 'consignacao',
            'ref_id' => $consignacao->id,
            'observacao' => 'Envio em consignacao.',
        ]);
        $devolucao = $movimentos->registrarMovimentacaoManual([
            'id_variacao' => $organico->id,
            'id_deposito_destino' => $loja->id,
            'tipo' => EstoqueMovimentacaoTipo::CONSIGNACAO_DEVOLUCAO->value,
            'quantidade' => 1,
            'pedido_id' => $cancelado->id,
            'ref_type' => 'consignacao',
            'ref_id' => $consignacao->id,
            'observacao' => 'Devolucao da consignacao.',
        ]);
        ConsignacaoDevolucao::create([
            'consignacao_id' => $consignacao->id,
            'usuario_id' => $usuario->id,
            'estoque_movimentacao_id' => $devolucao->id,
            'deposito_id' => $loja->id,
            'quantidade' => 1,
            'data_devolucao' => now(),
        ]);
        $movimentos->registrarMovimentacaoManual([
            'id_variacao' => $organico->id,
            'id_deposito_origem' => $loja->id,
            'tipo' => EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value,
            'quantidade' => 1,
            'observacao' => 'Venda e entrega posterior.',
        ]);
        $creditoDuplicado = $movimentos->registrarMovimentacaoManual([
            'id_variacao' => $organico->id,
            'id_deposito_destino' => $loja->id,
            'tipo' => EstoqueMovimentacaoTipo::ESTORNO->value,
            'quantidade' => 1,
            'ref_type' => 'estorno',
            'ref_id' => $envio->id,
            'observacao' => 'Credito duplicado historico.',
        ]);

        return [
            'reposicao' => $reposicao,
            'cancelado' => $cancelado,
            'usuario' => $usuario,
            'jb' => $jb,
            'loja' => $loja,
            'geometria' => $geometria,
            'gatsby' => $gatsby,
            'organico' => $organico,
            'item_geometria' => $itemGeometria,
            'item_gatsby' => $itemGatsby,
            'entrega_geometria' => $entregaGeometria,
            'entrega_gatsby' => $entregaGatsby,
            'credito_duplicado' => $creditoDuplicado,
        ];
    }

    /** @return array{ProdutoVariacao,PedidoItem,ProdutoEntregaItem} */
    private function criarRecebimentoCorrompido(
        Produto $produto,
        Pedido $pedido,
        Deposito $deposito,
        string $referencia,
        int $quantidadeRecebida
    ): array {
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => $referencia,
            'nome' => $referencia,
            'preco' => 1,
            'custo' => 1,
        ]);
        $pedidoItem = PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => 1,
            'preco_unitario' => 1,
            'subtotal' => 1,
        ]);
        $entrega = ProdutoEntregaItem::create([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
            'origem_id' => $pedido->id,
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $pedidoItem->id,
            'id_variacao' => $variacao->id,
            'quantidade_total' => 1,
            'quantidade_recebida' => $quantidadeRecebida,
            'id_deposito_destino' => $deposito->id,
            'status' => ProdutoEntregaItem::STATUS_RECEBIDO,
        ]);
        $movimentacao = app(EstoqueMovimentacaoService::class)->registrarMovimentacaoManual([
            'id_variacao' => $variacao->id,
            'id_deposito_destino' => $deposito->id,
            'tipo' => EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
            'quantidade' => $quantidadeRecebida,
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $pedidoItem->id,
            'ref_type' => 'pedido',
            'ref_id' => $pedido->id,
            'observacao' => 'Recebimento historico em M2.',
        ]);
        ProdutoEntregaEvento::create([
            'produto_entrega_item_id' => $entrega->id,
            'tipo_evento' => ProdutoEntregaEvento::RECEBIDO_ESTOQUE,
            'quantidade' => $quantidadeRecebida,
            'id_deposito_destino' => $deposito->id,
            'estoque_movimentacao_id' => $movimentacao->id,
            'observacao' => 'Recebimento historico em M2.',
            'idempotency_key' => "recebimento-historico-{$entrega->id}",
        ]);

        return [$variacao, $pedidoItem, $entrega];
    }

    private function saldo(ProdutoVariacao $variacao, Deposito $deposito): int
    {
        return (int) Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $deposito->id)
            ->value('quantidade');
    }
}
