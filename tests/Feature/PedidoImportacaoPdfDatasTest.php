<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Pedido;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PedidoImportacaoPdfDatasTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirma_importacao_com_data_dd_mm_yyyy(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuário Teste',
            'email' => 'usuario_ddmm@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create([
            'nome' => 'Categoria Teste',
        ]);

        $numeroExterno = 'IMP-' . Str::random(8);

        $payload = [
            'importacao_id' => null,
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => $numeroExterno,
                'total' => 100,
                'data_pedido' => '14/08/2020',
            ],
            'itens' => [
                [
                    'ref' => 'REF-TESTE-1',
                    'nome' => 'Produto Teste',
                    'quantidade' => 1,
                    'valor' => 100,
                    'id_categoria' => $categoria->id,
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', $payload);

        $response->assertStatus(200);

        $pedido = Pedido::where('numero_externo', $numeroExterno)->first();
        $this->assertNotNull($pedido);
        $this->assertSame('2020-08-14', $pedido->data_pedido->toDateString());
    }

    public function test_confirma_importacao_com_data_dd_mm_yy_ponto(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuário Teste 2',
            'email' => 'usuario_ddmmyy@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create([
            'nome' => 'Categoria Teste 2',
        ]);

        $numeroExterno = 'IMP-' . Str::random(8);

        $payload = [
            'importacao_id' => null,
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => $numeroExterno,
                'total' => 200,
                'data_pedido' => '14.08.20',
            ],
            'itens' => [
                [
                    'ref' => 'REF-TESTE-2',
                    'nome' => 'Produto Teste 2',
                    'quantidade' => 2,
                    'valor' => 100,
                    'id_categoria' => $categoria->id,
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', $payload);

        $response->assertStatus(200);

        $pedido = Pedido::where('numero_externo', $numeroExterno)->first();
        $this->assertNotNull($pedido);
        $this->assertSame('2020-08-14', $pedido->data_pedido->toDateString());
    }
}
