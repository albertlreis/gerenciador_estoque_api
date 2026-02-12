<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\OutletFormaPagamento;
use App\Models\OutletMotivo;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OutletCrudTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'teste@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        $categoria = Categoria::create(['nome' => 'Categoria Teste']);
        $produto = Produto::create([
            'nome' => 'Produto',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-OUTLET',
            'nome' => 'Variante',
            'preco' => 100,
            'custo' => 50,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito']);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 5]
        );

        $motivo = OutletMotivo::create([
            'slug' => 'tempo_estoque',
            'nome' => 'Tempo em estoque',
            'ativo' => true,
        ]);

        $formaPagamento = OutletFormaPagamento::create([
            'slug' => 'pix',
            'nome' => 'PIX',
            'max_parcelas_default' => null,
            'percentual_desconto_default' => 10,
            'ativo' => true,
        ]);

        return [$variacao, $motivo, $formaPagamento];
    }

    public function test_cadastra_outlet_para_variacao(): void
    {
        [$variacao, $motivo, $formaPagamento] = $this->seedBase();

        $response = $this->postJson("/api/v1/variacoes/{$variacao->id}/outlets", [
            'motivo_id' => $motivo->id,
            'quantidade' => 2,
            'formas_pagamento' => [
                [
                    'forma_pagamento_id' => $formaPagamento->id,
                    'percentual_desconto' => 10,
                    'max_parcelas' => null,
                ],
            ],
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('produto_variacao_outlets', [
            'produto_variacao_id' => $variacao->id,
            'quantidade' => 2,
            'quantidade_restante' => 2,
            'motivo_id' => $motivo->id,
        ]);
    }

    public function test_remove_outlet_da_variacao(): void
    {
        [$variacao, $motivo, $formaPagamento] = $this->seedBase();

        $create = $this->postJson("/api/v1/variacoes/{$variacao->id}/outlets", [
            'motivo_id' => $motivo->id,
            'quantidade' => 2,
            'formas_pagamento' => [
                [
                    'forma_pagamento_id' => $formaPagamento->id,
                    'percentual_desconto' => 10,
                    'max_parcelas' => null,
                ],
            ],
        ]);

        $create->assertStatus(201);
        $outletId = $create->json('data.id');

        $delete = $this->deleteJson("/api/v1/variacoes/{$variacao->id}/outlets/{$outletId}");
        $delete->assertStatus(200);

        $this->assertDatabaseMissing('produto_variacao_outlets', [
            'id' => $outletId,
        ]);
    }
}
