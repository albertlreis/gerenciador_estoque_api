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
            'tipo' => ' entrada ',
            'periodo' => ['2026-01-01', '2026-01-31'],
            'per_page' => 500,
            'estoque_status' => 'sem_estoque',
            'zerados' => '1',
            'sort_field' => 'referencia',
            'sort_order' => 'DESC',
        ]);

        $this->assertSame('Cabo USB', $dto->produto);
        $this->assertSame(5, $dto->deposito);
        $this->assertSame(2, $dto->categoria);
        $this->assertSame(9, $dto->fornecedor);
        $this->assertSame('entrada', $dto->tipo);
        $this->assertSame(['2026-01-01', '2026-01-31'], $dto->periodo);
        $this->assertSame(200, $dto->perPage);
        $this->assertSame('sem_estoque', $dto->estoqueStatus);
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
            'tipo' => '   ',
            'periodo' => ['2026-01-01', ''],
            'per_page' => 0,
            'estoque_status' => 'com_estoque',
            'zerados' => 'false',
            'sort_order' => 'invalido',
        ]);

        $this->assertNull($dto->produto);
        $this->assertNull($dto->deposito);
        $this->assertNull($dto->categoria);
        $this->assertNull($dto->fornecedor);
        $this->assertNull($dto->tipo);
        $this->assertNull($dto->periodo);
        $this->assertSame(1, $dto->perPage);
        $this->assertSame('com_estoque', $dto->estoqueStatus);
        $this->assertFalse($dto->zerados);
        $this->assertTrue($dto->comEstoque);
        $this->assertNull($dto->sortOrder);
    }
}
