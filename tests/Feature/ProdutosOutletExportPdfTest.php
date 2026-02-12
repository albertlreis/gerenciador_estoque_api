<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\OutletFormaPagamento;
use App\Models\OutletMotivo;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoOutlet;
use App\Models\ProdutoVariacaoOutletPagamento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutosOutletExportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_exporta_catalogo_outlet_em_pdf(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'outlet-export@test.local',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        $categoria = Categoria::create(['nome' => 'Categoria']);
        $produto = Produto::create([
            'nome' => 'Produto Outlet',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-OUT',
            'nome' => 'Var 1',
            'preco' => 100,
            'custo' => 60,
        ]);

        $motivo = OutletMotivo::create([
            'slug' => 'tempo_estoque',
            'nome' => 'Tempo em estoque',
            'ativo' => true,
        ]);

        $outlet = ProdutoVariacaoOutlet::create([
            'produto_variacao_id' => $variacao->id,
            'motivo_id' => $motivo->id,
            'quantidade' => 5,
            'quantidade_restante' => 5,
            'usuario_id' => $usuario->id,
        ]);

        $forma = OutletFormaPagamento::create([
            'slug' => 'pix',
            'nome' => 'PIX',
            'max_parcelas_default' => 1,
            'percentual_desconto_default' => 10,
            'ativo' => true,
        ]);

        ProdutoVariacaoOutletPagamento::create([
            'produto_variacao_outlet_id' => $outlet->id,
            'forma_pagamento_id' => $forma->id,
            'percentual_desconto' => 10,
            'max_parcelas' => 1,
        ]);

        $response = $this->postJson('/api/v1/produtos/outlet/export', [
            'ids' => [$produto->id],
            'format' => 'pdf',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }
}
