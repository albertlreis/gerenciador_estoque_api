<?php

namespace App\Integrations\ContaAzul\Import;

use App\Integrations\ContaAzul\ContaAzulEntityType;

class VendaContaAzulImportAdapter extends AbstractContaAzulImportAdapter
{
    public function tipoEntidade(): string
    {
        return ContaAzulEntityType::VENDA;
    }

    public function stagingTable(): string
    {
        return 'stg_conta_azul_vendas';
    }

    protected function settingsKey(): string
    {
        return 'venda';
    }

    protected function pathKey(): string
    {
        return 'vendas_busca';
    }

    protected function defaultPath(): string
    {
        return '/v1/venda/busca';
    }
}
