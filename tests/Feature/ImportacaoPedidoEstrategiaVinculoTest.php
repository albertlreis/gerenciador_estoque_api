<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportacaoPedidoEstrategiaVinculoTest extends TestCase
{
    use RefreshDatabase;

    public function test_forcar_produto_novo_cria_nova_variacao_mesmo_com_referencia_existente(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Estrategia',
            'email' => 'usuario_estrategia_' . Str::random(6) . '@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create(['nome' => 'Cat Estrategia']);
        $produto = Produto::create([
            'nome' => 'Poltrona Teste',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacaoExistente = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-DUP-EST',
            'nome' => 'Var existente',
            'preco' => 100,
            'custo' => 50,
        ]);

        $numeroExterno = 'IMP-EST-' . Str::random(8);

        $payload = [
            'importacao_id' => null,
            'estrategia_vinculo' => 'REF_SELECAO',
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => $numeroExterno,
                'total' => 80,
                'data_pedido' => '2024-01-10',
            ],
            'itens' => [
                [
                    'ref' => 'REF-DUP-EST',
                    'nome' => $produto->nome,
                    'quantidade' => 1,
                    'valor' => 80,
                    'preco_unitario' => 80,
                    'custo_unitario' => 40,
                    'id_categoria' => $categoria->id,
                    'forcar_produto_novo' => true,
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', $payload);

        $response->assertStatus(200);

        $this->assertSame(2, ProdutoVariacao::where('referencia', 'REF-DUP-EST')->count());

        $pedido = Pedido::where('numero_externo', $numeroExterno)->first();
        $this->assertNotNull($pedido);
        $item = $pedido->itens()->first();
        $this->assertNotNull($item);
        $this->assertNotSame($variacaoExistente->id, $item->id_variacao);
    }

    public function test_referencia_unica_sem_flag_vincula_variacao_existente(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Vinculo Unico',
            'email' => 'usuario_unico_' . Str::random(6) . '@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create(['nome' => 'Cat Vinculo Unico']);
        $produto = Produto::create([
            'nome' => 'Mesa Teste',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacaoExistente = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-UNICA-CONFIRM',
            'nome' => 'Var unica',
            'preco' => 200,
            'custo' => 100,
        ]);

        $numeroExterno = 'IMP-UNICA-' . Str::random(8);

        $payload = [
            'importacao_id' => null,
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => $numeroExterno,
                'total' => 120,
                'data_pedido' => '2024-02-01',
            ],
            'itens' => [
                [
                    'ref' => 'REF-UNICA-CONFIRM',
                    'nome' => $produto->nome,
                    'quantidade' => 1,
                    'valor' => 120,
                    'preco_unitario' => 120,
                    'custo_unitario' => 60,
                    'id_categoria' => $categoria->id,
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', $payload);

        $response->assertStatus(200);

        $pedido = Pedido::where('numero_externo', $numeroExterno)->first();
        $item = $pedido?->itens()->first();

        $this->assertNotNull($item);
        $this->assertSame($variacaoExistente->id, $item->id_variacao);
    }
}
