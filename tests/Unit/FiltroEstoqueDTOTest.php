<?php

namespace Tests\Unit;

use App\DTOs\FiltroEstoqueDTO;
use PHPUnit\Framework\TestCase;

class FiltroEstoqueDTOTest extends TestCase
{
    public function test_normaliza_campos_de_filtro_e_periodo(): void
    {
        $dto = new FiltroEstoqueDTO([
            'produto' => '  Cabo USB  ',
            'deposito' => '5',
            'categoria' => '2',
            'fornecedor' => '9',
            'localizacao_id' => '12',
            'tipo' => ' entrada ',
            'periodo' => ['2026-01-01', '2026-01-31'],
            'per_page' => 500,
            'estoque_status' => 'sem_estoque',
            'estoque_cliente_status' => 'reservado',
            'zerados' => '1',
            'sort_field' => 'referencia',
            'sort_order' => 'DESC',
        ]);

        $this->assertSame('Cabo USB', $dto->produto);
        $this->assertSame(5, $dto->deposito);
        $this->assertSame(2, $dto->categoria);
        $this->assertSame(9, $dto->fornecedor);
        $this->assertSame(12, $dto->localizacaoId);
        $this->assertSame('entrada', $dto->tipo);
        $this->assertSame(['2026-01-01', '2026-01-31'], $dto->periodo);
        $this->assertSame(200, $dto->perPage);
        $this->assertSame('sem_estoque', $dto->estoqueStatus);
        $this->assertSame('reservado', $dto->estoqueClienteStatus);
        $this->assertTrue($dto->estoqueCliente);
        $this->assertTrue($dto->zerados);
        $this->assertFalse($dto->comEstoque);
        $this->assertSame('referencia', $dto->sortField);
        $this->assertSame('desc', $dto->sortOrder);
    }

    public function test_aplica_defaults_quando_valores_invalidos(): void
    {
        $dto = new FiltroEstoqueDTO([
            'produto' => '   ',
            'deposito' => '0',
            'categoria' => '',
            'fornecedor' => null,
            'localizacao_id' => '0',
            'tipo' => '   ',
            'periodo' => ['2026-01-01', ''],
            'per_page' => 0,
            'estoque_status' => 'com_estoque',
            'estoque_cliente_status' => 'invalido',
            'zerados' => 'false',
            'sort_order' => 'invalido',
        ]);

        $this->assertNull($dto->produto);
        $this->assertNull($dto->deposito);
        $this->assertNull($dto->categoria);
        $this->assertNull($dto->fornecedor);
        $this->assertNull($dto->localizacaoId);
        $this->assertNull($dto->tipo);
        $this->assertNull($dto->periodo);
        $this->assertSame(1, $dto->perPage);
        $this->assertSame('com_estoque', $dto->estoqueStatus);
        $this->assertNull($dto->estoqueClienteStatus);
        $this->assertFalse($dto->estoqueCliente);
        $this->assertFalse($dto->zerados);
        $this->assertTrue($dto->comEstoque);
        $this->assertNull($dto->sortOrder);
    }

    public function test_estoque_cliente_legado_vira_todos_pendentes(): void
    {
        $dto = new FiltroEstoqueDTO([
            'estoque_cliente' => '1',
        ]);

        $this->assertSame('todos_pendentes', $dto->estoqueClienteStatus);
        $this->assertTrue($dto->estoqueCliente);
    }
}
