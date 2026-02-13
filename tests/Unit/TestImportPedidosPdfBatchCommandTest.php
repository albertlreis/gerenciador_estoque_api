<?php

namespace Tests\Unit;

use App\Console\Commands\TestImportPedidosPdfBatchCommand;
use App\Models\Categoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class TestImportPedidosPdfBatchCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_normaliza_item_com_campos_minimos_para_confirmacao(): void
    {
        $command = new TestImportPedidosPdfBatchCommand();
        $ref = new ReflectionClass($command);
        $method = $ref->getMethod('normalizarItensParaConfirmacao');
        $method->setAccessible(true);

        $items = $method->invoke($command, [[
            'codigo' => 'REF-001',
            'descricao' => 'Produto de Teste',
            'quantidade' => '2',
            'preco_unitario' => '10,50',
        ]]);

        $this->assertCount(1, $items);
        $this->assertSame('REF-001', $items[0]['ref']);
        $this->assertSame('Produto de Teste', $items[0]['nome']);
        $this->assertSame(2.0, (float) $items[0]['quantidade']);
        $this->assertSame(10.5, (float) $items[0]['preco_unitario']);
        $this->assertSame(10.5, (float) $items[0]['valor']);
        $this->assertSame(10.5, (float) $items[0]['custo_unitario']);
        $this->assertNotEmpty($items[0]['id_categoria']);
        $this->assertDatabaseHas('categorias', ['id' => (int) $items[0]['id_categoria']]);
    }

    public function test_categoria_padrao_revalida_cache_invalido(): void
    {
        $command = new TestImportPedidosPdfBatchCommand();
        $ref = new ReflectionClass($command);

        $property = $ref->getProperty('categoriaPadraoId');
        $property->setAccessible(true);
        $property->setValue($command, 999999);

        $method = $ref->getMethod('categoriaPadraoImportacao');
        $method->setAccessible(true);
        $categoriaId = (int) $method->invoke($command);

        $this->assertNotSame(999999, $categoriaId);
        $this->assertDatabaseHas('categorias', [
            'id' => $categoriaId,
            'nome' => 'Importacao PDF - Sem categoria',
        ]);
    }
}

