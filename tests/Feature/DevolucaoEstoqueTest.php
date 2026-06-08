<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use App\Services\DevolucaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevolucaoEstoqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_troca_em_devolucao_movimenta_entrada_original_e_saida_do_produto_novo(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Devolucao',
            'email' => uniqid('devolucao-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $cliente = Cliente::create([
            'nome' => 'Cliente Devolucao',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Devolucao']);
        $deposito = Deposito::create(['nome' => 'Deposito Devolucao']);

        $produtoOriginal = Produto::create([
            'nome' => 'Produto Original',
            'descricao' => 'Original',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacaoOriginal = ProdutoVariacao::create([
            'produto_id' => $produtoOriginal->id,
            'referencia' => 'DEV-ORIG',
            'nome' => 'Original',
            'preco' => 100,
            'custo' => 50,
        ]);

        $produtoNovo = Produto::create([
            'nome' => 'Produto Novo',
            'descricao' => 'Novo',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacaoNova = ProdutoVariacao::create([
            'produto_id' => $produtoNovo->id,
            'referencia' => 'DEV-NOVO',
            'nome' => 'Novo',
            'preco' => 80,
            'custo' => 40,
        ]);

        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 100,
        ]);

        $pedidoItem = PedidoItem::create([
            'id_pedido' => $pedido->id,
            'id_variacao' => $variacaoOriginal->id,
            'id_deposito' => $deposito->id,
            'quantidade' => 1,
            'preco_unitario' => 100,
            'subtotal' => 100,
        ]);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacaoOriginal->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 0]
        );
        Estoque::updateOrCreate(
            ['id_variacao' => $variacaoNova->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 2]
        );

        $devolucao = app(DevolucaoService::class)->iniciar([
            'pedido_id' => $pedido->id,
            'tipo' => 'troca',
            'motivo' => 'Troca por modelo novo',
            'itens' => [
                [
                    'pedido_item_id' => $pedidoItem->id,
                    'quantidade' => 1,
                    'trocas' => [
                        [
                            'nova_variacao_id' => $variacaoNova->id,
                            'quantidade' => 1,
                            'preco_unitario' => 80,
                        ],
                    ],
                ],
            ],
        ]);

        app(DevolucaoService::class)->aprovar((int) $devolucao->id);

        $this->assertSame(1, (int) Estoque::query()
            ->where('id_variacao', $variacaoOriginal->id)
            ->where('id_deposito', $deposito->id)
            ->value('quantidade'));
        $this->assertSame(1, (int) Estoque::query()
            ->where('id_variacao', $variacaoNova->id)
            ->where('id_deposito', $deposito->id)
            ->value('quantidade'));

        $this->assertSame(1, EstoqueMovimentacao::query()
            ->where('id_variacao', $variacaoOriginal->id)
            ->where('tipo', 'entrada_deposito')
            ->where('ref_type', 'devolucao')
            ->count());
        $this->assertSame(1, EstoqueMovimentacao::query()
            ->where('id_variacao', $variacaoNova->id)
            ->where('tipo', 'saida_entrega_cliente')
            ->where('ref_type', 'devolucao_troca')
            ->count());
    }
}
