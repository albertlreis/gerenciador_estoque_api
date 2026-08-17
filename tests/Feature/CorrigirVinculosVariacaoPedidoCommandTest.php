<?php

namespace Tests\Feature;

use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Produto;
use App\Models\ProdutoEntregaItem;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CorrigirVinculosVariacaoPedidoCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_simula_aplica_e_repete_de_forma_idempotente(): void
    {
        [$pedido, $item, $anterior, $correta] = $this->criarCenario();

        $argumentos = [
            '--pedido' => $pedido->id,
            '--item' => ["{$item->id}:{$correta->id}"],
        ];
        $this->artisan('pedidos:corrigir-vinculos-variacao', $argumentos)
            ->expectsOutputToContain('Simulacao concluida')
            ->assertSuccessful();
        $this->assertSame($anterior->id, $item->fresh()->id_variacao);

        $this->artisan('pedidos:corrigir-vinculos-variacao', $argumentos + ['--aplicar' => true])
            ->expectsOutputToContain('1 vinculo(s) corrigido(s)')
            ->assertSuccessful();
        $this->assertSame($correta->id, $item->fresh()->id_variacao);
        $this->assertDatabaseHas('produto_entrega_itens', [
            'pedido_item_id' => $item->id,
            'id_variacao' => $correta->id,
        ]);
        $this->assertDatabaseHas('auditoria_logs', [
            'modulo' => 'pedidos',
            'acao' => 'pedido_item.variacao_corrigida',
            'entity_id' => $pedido->id,
        ]);

        $this->artisan('pedidos:corrigir-vinculos-variacao', $argumentos + ['--aplicar' => true])
            ->expectsOutputToContain('Nenhuma alteracao necessaria')
            ->assertSuccessful();
        $this->assertSame($correta->id, $item->fresh()->id_variacao);
    }

    public function test_bloqueia_item_com_historico_operacional(): void
    {
        [$pedido, $item, $anterior, $correta] = $this->criarCenario();
        ProdutoEntregaItem::create([
            'tipo_origem' => ProdutoEntregaItem::ORIGEM_PEDIDO,
            'origem_id' => $pedido->id,
            'pedido_id' => $pedido->id,
            'pedido_item_id' => $item->id,
            'id_variacao' => $anterior->id,
            'quantidade_total' => 1,
            'quantidade_recebida' => 1,
            'status' => ProdutoEntregaItem::STATUS_RECEBIDO,
        ]);

        $this->artisan('pedidos:corrigir-vinculos-variacao', [
            '--pedido' => $pedido->id,
            '--item' => ["{$item->id}:{$correta->id}"],
            '--aplicar' => true,
        ])
            ->expectsOutputToContain('bloqueado_por_historico_operacional')
            ->assertExitCode(1);

        $this->assertSame($anterior->id, $item->fresh()->id_variacao);
    }

    private function criarCenario(): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Correcao Vinculo',
            'email' => 'correcao-vinculo-'.uniqid().'@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);
        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Tapetes '.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $produto = Produto::create([
            'nome' => 'Tapete',
            'id_categoria' => $categoriaId,
            'ativo' => true,
        ]);
        $anterior = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => '8205',
            'nome' => 'Medida incorreta',
            'preco' => 10,
            'custo' => 5,
        ]);
        $correta = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => '8205',
            'nome' => 'Medida correta',
            'preco' => 10,
            'custo' => 5,
        ]);
        $pedido = Pedido::create([
            'tipo' => Pedido::TIPO_REPOSICAO,
            'origem_abastecimento' => Pedido::ORIGEM_ABASTECIMENTO_FABRICA,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 10,
        ]);
        $item = PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $anterior->id,
            'quantidade' => 1,
            'preco_unitario' => 10,
            'subtotal' => 10,
        ]);

        return [$pedido, $item, $anterior, $correta];
    }
}
