<?php

namespace Tests\Unit\Integrations\ContaAzul;

use App\Console\Commands\ContaAzul\ContaAzulImportAllCommand;
use App\Console\Commands\ContaAzul\ContaAzulImportCommand;
use Tests\TestCase;

class ContaAzulFinancialCommandCoverageTest extends TestCase
{
    public function test_import_tudo_inclui_financeiro_completo_em_ordem_operacional(): void
    {
        $this->assertSame([
            'contas_financeiras',
            'categorias_financeiras',
            'centros_custo',
            'pessoas',
            'produtos',
            'vendas',
            'financeiro',
            'contas_pagar',
            'parcelas',
            'baixas',
            'saldos_contas_financeiras',
            'formas_pagamento',
            'notas',
        ], ContaAzulImportAllCommand::ENTIDADES_FINANCEIRO_COMPLETO);

        foreach (ContaAzulImportAllCommand::ENTIDADES_FINANCEIRO_COMPLETO as $entidade) {
            $this->assertArrayHasKey($entidade, ContaAzulImportCommand::ENTIDADES_SUPORTADAS);
        }
    }
}
