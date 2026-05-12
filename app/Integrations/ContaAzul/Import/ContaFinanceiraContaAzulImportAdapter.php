<?php

namespace App\Integrations\ContaAzul\Import;

use App\Integrations\ContaAzul\ContaAzulEntityType;

class ContaFinanceiraContaAzulImportAdapter extends AbstractContaAzulImportAdapter
{
    public function tipoEntidade(): string
    {
        return ContaAzulEntityType::CONTA_FINANCEIRA;
    }

    public function stagingTable(): string
    {
        return 'stg_conta_azul_contas_financeiras';
    }

    protected function settingsKey(): string
    {
        return 'conta_financeira';
    }

    protected function pathKey(): string
    {
        return 'contas_financeiras';
    }

    protected function defaultPath(): string
    {
        return '/v1/conta-financeira';
    }
}
