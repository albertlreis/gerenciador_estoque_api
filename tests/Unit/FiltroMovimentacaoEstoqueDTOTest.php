<?php

namespace Tests\Unit;

use App\DTOs\FiltroMovimentacaoEstoqueDTO;
use PHPUnit\Framework\TestCase;

class FiltroMovimentacaoEstoqueDTOTest extends TestCase
{
    public function test_parseia_filtros_de_movimentacao_com_novos_campos(): void
    {
        $dto = new FiltroMovimentacaoEstoqueDTO([
            'variacao' => '10',
            'tipo' => ' transferencia ',
            'produto' => '  Produto X ',
            'deposito' => '4',
            'categoria' => '7',
            'fornecedor' => '8',
            'localizacao_id' => '15',
            'localizacao' => ' A-01 ',
            'periodo' => ['2026-01-01', '2026-01-31'],
            'estoque_cliente_status' => 'pendente_entrega',
            'sort_field' => 'tipo',
            'sort_order' => 'asc',
            'per_page' => 25,
        ]);

        $this->assertSame(10, $dto->variacao);
        $this->assertSame('transferencia', $dto->tipo);
        $this->assertSame('Produto X', $dto->produto);
        $this->assertSame(4, $dto->deposito);
        $this->assertSame(7, $dto->categoria);
        $this->assertSame(8, $dto->fornecedor);
        $this->assertSame(15, $dto->localizacaoId);
        $this->assertSame('A-01', $dto->localizacao);
        $this->assertSame(['2026-01-01', '2026-01-31'], $dto->periodo);
        $this->assertSame('pendente_entrega', $dto->estoqueClienteStatus);
        $this->assertTrue($dto->estoqueCliente);
        $this->assertSame('tipo', $dto->sortField);
        $this->assertSame('asc', $dto->sortOrder);
        $this->assertSame(25, $dto->perPage);
    }

    public function test_converte_brancos_e_zeros_para_null(): void
    {
        $dto = new FiltroMovimentacaoEstoqueDTO([
            'variacao' => '0',
            'tipo' => '   ',
            'produto' => '   ',
            'deposito' => '0',
            'categoria' => '',
            'fornecedor' => '-1',
            'localizacao_id' => '0',
            'localizacao' => '   ',
            'estoque_cliente_status' => 'invalido',
        ]);

        $this->assertNull($dto->variacao);
        $this->assertNull($dto->tipo);
        $this->assertNull($dto->produto);
        $this->assertNull($dto->deposito);
        $this->assertNull($dto->categoria);
        $this->assertNull($dto->fornecedor);
        $this->assertNull($dto->localizacaoId);
        $this->assertNull($dto->localizacao);
        $this->assertNull($dto->estoqueClienteStatus);
        $this->assertFalse($dto->estoqueCliente);
        $this->assertSame('desc', $dto->sortOrder);
        $this->assertSame(10, $dto->perPage);
    }

    public function test_estoque_cliente_legado_vira_todos_pendentes(): void
    {
        $dto = new FiltroMovimentacaoEstoqueDTO([
            'estoque_cliente' => true,
        ]);

        $this->assertSame('todos_pendentes', $dto->estoqueClienteStatus);
        $this->assertTrue($dto->estoqueCliente);
    }
}
