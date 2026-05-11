<?php

namespace App\Integrations\ContaAzul\Import;

use App\Integrations\ContaAzul\ContaAzulEntityType;

class ContaPagarContaAzulImportAdapter extends AbstractContaAzulImportAdapter
{
    public function tipoEntidade(): string
    {
        return ContaAzulEntityType::CONTA_PAGAR;
    }

    public function stagingTable(): string
    {
        return 'stg_conta_azul_contas_pagar';
    }

    protected function settingsKey(): string
    {
        return 'conta_pagar';
    }

    protected function pathKey(): string
    {
        return 'contas_pagar_list';
    }

    protected function defaultPath(): string
    {
        return '/v1/financeiro/eventos-financeiros/contas-a-pagar/buscar';
    }
}
