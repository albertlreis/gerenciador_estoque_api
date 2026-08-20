<?php

namespace Tests\Feature;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueReserva;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoReconciliacao;
use App\Models\PedidoReconciliacaoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use App\Services\PedidoEntregaReconciliacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PedidoEntregaReconciliacaoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_aplica_baixa_e_entrega_sem_duplicar_status_e_e_idempotente(): void
    {
        [$usuario, $pedido, $item, $entrega, $deposito] = $this->cenario(1, 1, 0);
        $lote = (string) Str::uuid();
        $linhas = collect([$this->linha($lote, $pedido, $item, $deposito, 1, 0, PedidoReconciliacaoItem::ACAO_BAIXAR_E_ENTREGAR)]);

        $service = app(PedidoEntregaReconciliacaoService::class);
        $resultado = $service->aplicar($linhas, $usuario->id, $lote);

        $this->assertFalse($resultado['ja_aplicado']);
        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $item->id_variacao)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertDatabaseHas('estoque_movimentacoes', [
            'pedido_item_id' => $item->id,
            'tipo' => EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value,
            'quantidade' => 1,
            'lote_id' => $lote,
        ]);
        $this->assertSame(1, $entrega->fresh()->quantidade_expedida);
        $this->assertSame(1, $entrega->fresh()->quantidade_entregue);
        $this->assertSame(1, PedidoStatusHistorico::query()->where('pedido_id', $pedido->id)->count());

        $repetido = $service->aplicar($linhas, $usuario->id, $lote);
        $this->assertTrue($repetido['ja_aplicado']);
        $this->assertSame(1, EstoqueMovimentacao::query()->where('lote_id', $lote)->count());
    }

    public function test_documenta_entrega_sem_criar_movimentacao_fisica(): void
    {
        [$usuario, $pedido, $item, $entrega, $deposito] = $this->cenario(0, 1, 0);
        $lote = (string) Str::uuid();
        $linhas = collect([$this->linha($lote, $pedido, $item, $deposito, 0, 0, PedidoReconciliacaoItem::ACAO_DOCUMENTAR_SEM_BAIXA)]);

        app(PedidoEntregaReconciliacaoService::class)->aplicar($linhas, $usuario->id, $lote);

        $this->assertSame(1, $entrega->fresh()->quantidade_entregue);
        $this->assertSame(0, EstoqueMovimentacao::query()->where('lote_id', $lote)->count());
        $this->assertDatabaseHas('pedido_reconciliacao_itens', [
            'pedido_item_id' => $item->id,
            'acao' => PedidoReconciliacaoItem::ACAO_DOCUMENTAR_SEM_BAIXA,
            'status' => 'aplicada',
        ]);
        $evento = ProdutoEntregaEvento::query()->where('idempotency_key', "reconciliacao:{$lote}:item:{$item->id}:entregar")->firstOrFail();
        $this->assertTrue((bool) $evento->metadata_json['confirmado_sem_saldo']);
    }

    public function test_ajusta_tapete_e_rollback_restaura_saldo(): void
    {
        [$usuario, $pedido, $item, $entrega, $deposito] = $this->cenario(1, 1, 1, '10.9884-4');
        $lote = (string) Str::uuid();
        $linha = $this->linha($lote, $pedido, $item, $deposito, 1, 0, PedidoReconciliacaoItem::ACAO_AJUSTAR_SALDO);
        $linha['tipo_registro'] = 'AJUSTE_ESTOQUE';
        $linha['classificacao'] = 'SALDO_FANTASMA_CONSIGNACAO';
        $linhas = collect([$linha]);

        $service = app(PedidoEntregaReconciliacaoService::class);
        $service->aplicar($linhas, $usuario->id, $lote);
        $this->assertSame(0, (int) Estoque::query()->where('id_variacao', $item->id_variacao)->where('id_deposito', $deposito->id)->value('quantidade'));

        $rollback = $service->estornar($lote, $usuario->id, $lote);
        $this->assertSame(1, $rollback['itens_estornados']);
        $this->assertSame(1, (int) Estoque::query()->where('id_variacao', $item->id_variacao)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame('estornada', PedidoReconciliacao::query()->firstOrFail()->status);
    }

    public function test_dry_run_do_comando_nao_altera_dados(): void
    {
        [$usuario, $pedido, $item, $entrega, $deposito] = $this->cenario(1, 1, 0);
        $lote = (string) Str::uuid();
        $arquivo = tempnam(sys_get_temp_dir(), 'reconciliacao-').'.csv';
        $this->escreverCsv($arquivo, [$this->linha($lote, $pedido, $item, $deposito, 1, 0, PedidoReconciliacaoItem::ACAO_BAIXAR_E_ENTREGAR)]);

        $this->artisan('pedidos:reconciliar-entregas', ['--manifesto' => $arquivo, '--usuario' => $usuario->id])
            ->assertSuccessful();

        $this->assertSame(1, (int) Estoque::query()->where('id_variacao', $item->id_variacao)->where('id_deposito', $deposito->id)->value('quantidade'));
        $this->assertSame(0, PedidoReconciliacao::query()->count());
        @unlink($arquivo);
    }

    public function test_exporta_manifesto_compativel_com_auditoria(): void
    {
        [$usuario, $pedido, $item, $entrega, $deposito] = $this->cenario(1, 1, 0);
        $item->update(['id_deposito' => null]);
        $entrega->update(['id_deposito_destino' => $deposito->id]);
        $arquivo = tempnam(sys_get_temp_dir(), 'manifesto-export-').'.csv';

        $service = app(PedidoEntregaReconciliacaoService::class);
        $resultado = $service->exportar($arquivo);
        $linhas = $service->lerManifesto($arquivo);

        $this->assertSame(1, $resultado['linhas']);
        $this->assertSame((string) $pedido->id, $linhas->first()['pedido_id']);
        $this->assertSame((string) $item->id, $linhas->first()['pedido_item_id']);
        $this->assertSame((string) $deposito->id, $linhas->first()['deposito_id']);
        $this->assertSame(PedidoReconciliacaoItem::ACAO_BAIXAR_E_ENTREGAR, $linhas->first()['acao']);

        $linha = $linhas->first();
        $linha['saldo_fisico_confirmado'] = '0';
        $linha['confirmacao_documental'] = 'SIM';
        $linha['confirmacao_fisica'] = 'SIM';
        $linha['evidencia'] = 'Confirmacao documental do teste.';
        $linha['justificativa'] = 'Reconstrucao da baixa ausente no teste.';
        $this->assertTrue($service->analisar(collect([$linha]), $usuario->id)['valido']);
        @unlink($arquivo);
    }

    public function test_bloqueia_manifesto_desatualizado_e_reserva_de_outro_pedido(): void
    {
        [$usuario, $pedido, $item, , $deposito] = $this->cenario(2, 1, 0);
        $lote = (string) Str::uuid();
        $linha = $this->linha($lote, $pedido, $item, $deposito, 2, 1, PedidoReconciliacaoItem::ACAO_BAIXAR_E_ENTREGAR);

        EstoqueReserva::create([
            'id_variacao' => $item->id_variacao,
            'id_deposito' => $deposito->id,
            'pedido_id' => null,
            'id_usuario' => $usuario->id,
            'quantidade' => 2,
            'quantidade_consumida' => 0,
            'status' => 'ativa',
            'motivo' => 'reserva_terceiro_teste',
        ]);

        $analiseReserva = app(PedidoEntregaReconciliacaoService::class)->analisar(collect([$linha]), $usuario->id);
        $this->assertFalse($analiseReserva['valido']);
        $this->assertTrue(collect($analiseReserva['erros'])->contains(fn ($erro) => str_contains($erro, 'reserva de outro pedido')));

        EstoqueReserva::query()->delete();
        Estoque::query()->where('id_variacao', $item->id_variacao)->where('id_deposito', $deposito->id)->update(['quantidade' => 3]);
        $analiseSaldo = app(PedidoEntregaReconciliacaoService::class)->analisar(collect([$linha]), $usuario->id);
        $this->assertFalse($analiseSaldo['valido']);
        $this->assertTrue(collect($analiseSaldo['erros'])->contains(fn ($erro) => str_contains($erro, 'saldo mudou')));
        $this->assertSame(0, PedidoReconciliacao::query()->count());
    }

    /** @return array{Usuario,Pedido,PedidoItem,ProdutoEntregaItem,Deposito} */
    private function cenario(int $saldo, int $quantidade, int $entregue, string $referencia = 'REF-REC'): array
    {
        $usuario = Usuario::create([
            'nome' => 'Operador Reconciliação',
            'email' => uniqid('reconciliacao-', true).'@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        $categoria = Categoria::create(['nome' => uniqid('Categoria ', true)]);
        $produto = Produto::create(['nome' => uniqid('Produto ', true), 'id_categoria' => $categoria->id, 'ativo' => true]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => $referencia,
            'nome' => 'Variação',
            'preco' => 100,
            'custo' => 50,
        ]);
        $deposito = Deposito::create(['nome' => uniqid('Depósito ', true)]);
        $pedido = Pedido::create([
            'tipo' => Pedido::TIPO_VENDA,
            'id_usuario' => $usuario->id,
            'numero_externo' => (string) random_int(10000, 99999),
            'data_pedido' => now()->subMonth(),
            'valor_total' => 100,
        ]);
        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::ENTREGA_CLIENTE,
            'data_status' => now()->subWeek(),
            'usuario_id' => $usuario->id,
        ]);
        $item = PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => $quantidade,
            'preco_unitario' => 100,
            'subtotal' => 100,
        ]);
        $entrega = ProdutoEntregaItem::create([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
            'origem_id' => $pedido->id,
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $item->id,
            'id_variacao' => $variacao->id,
            'quantidade_total' => $quantidade,
            'quantidade_expedida' => 0,
            'quantidade_entregue' => $entregue,
            'id_deposito_origem' => $deposito->id,
            'status' => $entregue >= $quantidade ? ProdutoEntregaItem::STATUS_ENTREGUE : ProdutoEntregaItem::STATUS_AGUARDANDO_ESTOQUE,
        ]);
        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => $saldo]
        );

        return [$usuario, $pedido, $item, $entrega, $deposito];
    }

    /** @return array<string,string> */
    private function linha(string $lote, Pedido $pedido, PedidoItem $item, Deposito $deposito, int $snapshot, int $fisico, string $acao): array
    {
        return [
            'lote_id' => $lote,
            'tipo_registro' => 'PEDIDO',
            'pedido_id' => (string) $pedido->id,
            'numero_externo' => (string) $pedido->numero_externo,
            'pedido_item_id' => (string) $item->id,
            'id_variacao' => (string) $item->id_variacao,
            'referencia' => (string) $item->variacao()->value('referencia'),
            'deposito_id' => (string) $deposito->id,
            'classificacao' => 'ENTREGA_INCOMPLETA',
            'quantidade_pendente' => (string) $item->quantidade,
            'acao' => $acao,
            'saldo_sistema_snapshot' => (string) $snapshot,
            'saldo_fisico_confirmado' => (string) $fisico,
            'confirmacao_documental' => 'SIM',
            'confirmacao_fisica' => 'SIM',
            'data_ocorrencia' => now()->subWeek()->format('Y-m-d H:i:s'),
            'evidencia' => 'Comprovante documental conferido.',
            'justificativa' => 'Correção controlada de teste.',
            '_linha' => '2',
        ];
    }

    /** @param array<int,array<string,string>> $linhas */
    private function escreverCsv(string $arquivo, array $linhas): void
    {
        $handle = fopen($arquivo, 'wb');
        fputcsv($handle, PedidoEntregaReconciliacaoService::COLUNAS, ';');
        foreach ($linhas as $linha) {
            fputcsv($handle, array_map(fn ($coluna) => $linha[$coluna] ?? '', PedidoEntregaReconciliacaoService::COLUNAS), ';');
        }
        fclose($handle);
    }
}
