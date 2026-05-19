<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\OutletFormaPagamento;
use App\Models\OutletMotivo;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoOutlet;
use App\Models\ProdutoVariacaoOutletPagamento;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EstoqueAtualFiltroTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): array
    {
        $suffix = uniqid();

        $usuario = Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'estoque.' . $suffix . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        $categoria = Categoria::create(['nome' => 'Categoria Teste']);
        $produto = Produto::create([
            'nome' => 'Produto Estoque',
            'descricao' => 'Desc',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacaoCom = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-COM-' . $suffix,
            'sku_interno' => 'SKU-COM-' . $suffix,
            'nome' => 'Variacao Com',
            'preco' => 100,
            'custo' => 50,
        ]);

        $variacaoSem = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'REF-SEM-' . $suffix,
            'sku_interno' => 'SKU-SEM-' . $suffix,
            'nome' => 'Variacao Sem',
            'preco' => 120,
            'custo' => 60,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito Teste']);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacaoCom->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 5]
        );

        Estoque::updateOrCreate(
            ['id_variacao' => $variacaoSem->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 0]
        );

        return [$variacaoCom, $variacaoSem];
    }

    public function test_filtra_apenas_com_estoque(): void
    {
        [$variacaoCom, $variacaoSem] = $this->seedBase();

        $response = $this->getJson('/api/v1/estoque/atual?estoque_status=com_estoque');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoCom->id, $ids);
        $this->assertNotContains($variacaoSem->id, $ids);
    }

    public function test_filtra_apenas_sem_estoque(): void
    {
        [$variacaoCom, $variacaoSem] = $this->seedBase();

        $response = $this->getJson('/api/v1/estoque/atual?estoque_status=sem_estoque');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoSem->id, $ids);
        $this->assertNotContains($variacaoCom->id, $ids);
    }

    public function test_compatibilidade_zerados_ainda_funciona(): void
    {
        [$variacaoCom, $variacaoSem] = $this->seedBase();

        $response = $this->getJson('/api/v1/estoque/atual?zerados=1');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoSem->id, $ids);
        $this->assertNotContains($variacaoCom->id, $ids);
    }

    public function test_filtro_produto_com_barra_na_referencia_funciona_no_estoque_atual(): void
    {
        [$variacaoCom, $variacaoSem] = $this->seedBase();

        $variacaoCom->update(['referencia' => 'MOV/ABC-123']);
        $variacaoSem->update(['referencia' => 'OUTRO-ITEM']);

        $response = $this->getJson('/api/v1/estoque/atual?produto=MOV%2FABC-123');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoCom->id, $ids);
        $this->assertNotContains($variacaoSem->id, $ids);
    }

    public function test_filtro_produto_por_sku_interno_funciona_no_estoque_atual(): void
    {
        [$variacaoCom, $variacaoSem] = $this->seedBase();

        $response = $this->getJson('/api/v1/estoque/atual?produto=' . urlencode((string) $variacaoCom->sku_interno));
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoCom->id, $ids);
        $this->assertNotContains($variacaoSem->id, $ids);
        $this->assertSame($variacaoCom->sku_interno, data_get($response->json('data.0'), 'produto_sku_interno'));
    }

    public function test_retorna_dias_sem_venda_desde_entrada_quando_nunca_vendeu(): void
    {
        [$variacaoCom] = $this->seedBase();

        Estoque::query()
            ->where('id_variacao', $variacaoCom->id)
            ->update([
                'quantidade' => 5,
                'ultima_venda_em' => null,
                'data_entrada_estoque_atual' => Carbon::now()->subDays(12)->startOfDay(),
            ]);

        $response = $this->getJson('/api/v1/estoque/atual?estoque_status=com_estoque');
        $response->assertOk();

        $linha = collect($response->json('data'))->firstWhere('variacao_id', $variacaoCom->id);
        $this->assertNotNull($linha);
        $this->assertGreaterThanOrEqual(12, (int) $linha['dias_sem_venda']);
    }

    public function test_filtro_dias_sem_venda_min_filtra_listagem_e_resumo(): void
    {
        [$variacaoCom, $variacaoSem] = $this->seedBase();

        Estoque::query()
            ->where('id_variacao', $variacaoCom->id)
            ->update([
                'quantidade' => 5,
                'ultima_venda_em' => null,
                'data_entrada_estoque_atual' => Carbon::now()->subDays(20)->startOfDay(),
            ]);

        Estoque::query()
            ->where('id_variacao', $variacaoSem->id)
            ->update([
                'quantidade' => 3,
                'ultima_venda_em' => Carbon::now()->subDays(3)->startOfDay(),
                'data_entrada_estoque_atual' => Carbon::now()->subDays(30)->startOfDay(),
            ]);

        $produtoFiltro = urlencode((string) $variacaoCom->sku_interno);

        $response = $this->getJson('/api/v1/estoque/atual?dias_sem_venda_min=10&estoque_status=com_estoque&produto=' . $produtoFiltro);
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($variacaoCom->id, $ids);
        $this->assertNotContains($variacaoSem->id, $ids);

        $resumo = $this->getJson('/api/v1/estoque/resumo?dias_sem_venda_min=10&estoque_status=com_estoque&produto=' . $produtoFiltro);
        $resumo->assertOk();
        $resumoPayload = $resumo->json('data') ?? $resumo->json();
        $this->assertSame(1, (int) ($resumoPayload['totalProdutos'] ?? 0));
        $this->assertGreaterThanOrEqual(5, (int) ($resumoPayload['totalPecas'] ?? 0));
    }

    public function test_estoque_atual_retorna_campos_de_outlet(): void
    {
        [$variacaoCom] = $this->seedBase();
        $suffix = uniqid('', true);

        $motivo = OutletMotivo::create([
            'slug' => 'baixa_rotatividade_' . str_replace('.', '_', $suffix),
            'nome' => 'Baixa rotatividade',
            'ativo' => true,
        ]);
        $forma = OutletFormaPagamento::create([
            'slug' => 'pix-estoque-atual-' . $suffix,
            'nome' => 'PIX Estoque Atual',
            'ativo' => true,
        ]);
        $outlet = ProdutoVariacaoOutlet::create([
            'produto_variacao_id' => $variacaoCom->id,
            'motivo_id' => $motivo->id,
            'quantidade' => 2,
            'quantidade_restante' => 2,
        ]);
        ProdutoVariacaoOutletPagamento::create([
            'produto_variacao_outlet_id' => $outlet->id,
            'forma_pagamento_id' => $forma->id,
            'percentual_desconto' => 10,
            'max_parcelas' => 1,
        ]);

        $response = $this->getJson('/api/v1/estoque/atual?estoque_status=com_estoque');
        $response->assertOk();

        $linha = collect($response->json('data'))->firstWhere('variacao_id', $variacaoCom->id);
        $this->assertSame(2, (int) $linha['estoque_outlet_total']);
        $this->assertSame(2, (int) $linha['outlet_restante_total']);
        $this->assertTrue((bool) $linha['is_outlet']);
    }
}
