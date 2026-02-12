<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\PedidoItem;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PedidoImportacaoPdfCustoTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirma_importacao_persistindo_custo_unitario_a_partir_de_preco_unitario(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Custo',
            'email' => 'usuario_custo@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create([
            'nome' => 'Categoria Custo',
        ]);

        $payload = [
            'importacao_id' => null,
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => 'IMP-CST-' . Str::random(8),
                'total' => 240,
                'data_pedido' => '2025-01-10',
            ],
            'itens' => [
                [
                    'ref' => 'REF-CST-1',
                    'nome' => 'Produto Custo',
                    'quantidade' => 2,
                    'valor' => 120,
                    'preco_unitario' => 45.9,
                    'id_categoria' => $categoria->id,
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', $payload);

        $response->assertStatus(200);

        $item = PedidoItem::query()->first();
        $this->assertNotNull($item);
        $this->assertSame('45.90', number_format((float) $item->custo_unitario, 2, '.', ''));
        $this->assertSame('120.00', number_format((float) $item->preco_unitario, 2, '.', ''));
    }

    public function test_confirma_importacao_rejeita_item_sem_preco_unitario_e_sem_custo_unitario(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Legado',
            'email' => 'usuario_legacy@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create([
            'nome' => 'Categoria Legado',
        ]);

        $payload = [
            'importacao_id' => null,
            'cliente' => [],
            'pedido' => [
                'tipo' => 'reposicao',
                'numero_externo' => 'IMP-LEG-' . Str::random(8),
                'total' => 180,
                'data_pedido' => '2025-01-10',
            ],
            'itens' => [
                [
                    'ref' => 'REF-LEG-1',
                    'nome' => 'Produto Legado',
                    'quantidade' => 1,
                    'valor' => 180,
                    'id_categoria' => $categoria->id,
                ],
            ],
        ];

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/pedidos/import/pdf/confirm', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['itens.0.preco_unitario']);

        $this->assertDatabaseCount('pedido_itens', 0);
    }
}
