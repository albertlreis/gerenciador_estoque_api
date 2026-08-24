<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoItemStatusHistorico;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use App\Services\EntregaProdutoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CorrigirRecebimentoNaoEmbarcadoCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostica_e_corrige_recebimento_nao_embarcado_de_reposicao(): void
    {
        $categoria = Categoria::create(['nome' => 'Categoria correcao']);
        $produto = Produto::create(['nome' => 'Produto correcao', 'id_categoria' => $categoria->id, 'ativo' => true]);
        $deposito = Deposito::create(['nome' => 'Deposito correcao']);
        $usuario = Usuario::create([
            'nome' => 'Usuario correcao',
            'email' => 'correcao-20405@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        $pedido = Pedido::create([
            'tipo' => Pedido::TIPO_REPOSICAO,
            'numero_externo' => '20405',
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 2,
        ]);
        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::PEDIDO_CRIADO,
            'data_status' => now(),
        ]);

        $itens = collect(['EMBARCADO', '10571C'])->map(function (string $referencia) use ($produto, $deposito, $pedido) {
            $variacao = ProdutoVariacao::create([
                'produto_id' => $produto->id,
                'referencia' => $referencia,
                'nome' => $referencia,
                'preco' => 1,
                'custo' => 1,
            ]);

            return PedidoItem::create([
                'id_pedido' => $pedido->id,
                'id_variacao' => $variacao->id,
                'id_deposito' => $deposito->id,
                'quantidade' => 1,
                'preco_unitario' => 1,
                'subtotal' => 1,
            ]);
        });

        $service = app(EntregaProdutoService::class);
        $entregas = $service->criarDemandaPedido($pedido->fresh('itens'), null, false)->keyBy('pedido_item_id');
        foreach ($itens as $indice => $item) {
            $service->receberItem(
                $entregas->get($item->id),
                $deposito->id,
                1,
                null,
                'Recebimento historico.',
                "correcao-recebimento-{$indice}",
                ocorridoEm: now()->addSeconds($indice),
                rejeitarExcesso: true
            );
        }

        PedidoItemStatusHistorico::create([
            'grupo_uuid' => (string) Str::uuid(),
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $itens->first()->id,
            'status' => PedidoStatus::EMBARQUE_FABRICA,
            'quantidade' => 1,
            'quantidade_avancada' => 1,
            'data_status' => now(),
        ]);

        $this->artisan('pedidos:corrigir-recebimento-nao-embarcado 20405')
            ->expectsOutputToContain('dry-run')
            ->assertExitCode(0);
        $this->assertSame(1, (int) $entregas->get($itens->last()->id)->fresh()->quantidade_recebida);

        $this->artisan('pedidos:corrigir-recebimento-nao-embarcado 20405 --aplicar --confirmacao=20405')
            ->expectsOutputToContain('Correcao aplicada')
            ->assertExitCode(0);

        $itemCorrigido = $entregas->get($itens->last()->id)->fresh();
        $this->assertSame(0, (int) $itemCorrigido->quantidade_recebida);
        $this->assertSame(0, (int) Estoque::query()
            ->where('id_variacao', $itemCorrigido->id_variacao)
            ->where('id_deposito', $deposito->id)
            ->value('quantidade'));
        $this->assertDatabaseHas('produto_entrega_eventos', [
            'produto_entrega_item_id' => $itemCorrigido->id,
            'tipo_evento' => ProdutoEntregaEvento::ESTORNADO,
        ]);
        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::FINALIZADO->value,
        ]);
        $this->assertDatabaseMissing('pedido_status_historico', [
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::ENTREGA_ESTOQUE->value,
        ]);

        $this->artisan('pedidos:corrigir-recebimento-nao-embarcado 20405 --aplicar --confirmacao=20405')
            ->expectsOutputToContain('nenhuma alteracao e necessaria')
            ->assertExitCode(0);
    }
}
