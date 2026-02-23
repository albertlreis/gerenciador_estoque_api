<?php

namespace Tests\Unit;

use App\Services\ImportacaoPedidoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportacaoPedidoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mescla_define_categoria_padrao_quando_item_nao_tem_categoria(): void
    {
        $service = app(ImportacaoPedidoService::class);

        $itens = $service->mesclarItensComVariacoes([
            [
                'codigo' => 'REF-SEM-CAT-001',
                'descricao' => 'Produto sem categoria',
                'quantidade' => '1.00',
                'preco_unitario' => '10.00',
            ],
        ]);

        $this->assertCount(1, $itens);
        $this->assertNotNull($itens[0]['id_categoria']);
        $this->assertSame('Importacao PDF - Sem categoria', $itens[0]['categoria']);
        $this->assertDatabaseHas('categorias', [
            'id' => (int) $itens[0]['id_categoria'],
            'nome' => 'Importacao PDF - Sem categoria',
        ]);
    }
}
