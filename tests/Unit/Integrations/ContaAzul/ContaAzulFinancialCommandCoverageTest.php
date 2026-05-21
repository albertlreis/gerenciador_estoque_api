<?php

namespace Tests\Unit\Integrations\ContaAzul;

use App\Console\Commands\ContaAzul\ContaAzulImportAllCommand;
use App\Console\Commands\ContaAzul\ContaAzulImportCommand;
use App\Integrations\ContaAzul\Services\ImportacaoContaAzulService;
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

    public function test_notas_fiscais_usam_chave_acesso_como_identificador_externo(): void
    {
        $service = (new \ReflectionClass(ImportacaoContaAzulService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ImportacaoContaAzulService::class, 'extractExternalId');
        $method->setAccessible(true);

        $this->assertSame(
            '15260354129336000188550010000001611306815230',
            $method->invoke($service, [
                'data_emissao' => '2026-03-05T14:20:28.566Z',
                'numero_nota' => 161,
                'chave_acesso' => '15260354129336000188550010000001611306815230',
                'status' => 'EMITIDA',
            ])
        );
    }
}
