<?php

namespace App\Integrations\ContaAzul\Import;

use App\Integrations\ContaAzul\ContaAzulEntityType;

class TituloContaAzulImportAdapter extends AbstractContaAzulImportAdapter
{
    public function tipoEntidade(): string
    {
        return ContaAzulEntityType::TITULO;
    }

    public function stagingTable(): string
    {
        return 'stg_conta_azul_financeiro';
    }

    protected function settingsKey(): string
    {
        return 'titulo';
    }

    protected function pathKey(): string
    {
        return 'titulos_list';
    }

    protected function defaultPath(): string
    {
        return '/v1/financeiro/eventos-financeiros/contas-a-receber/buscar';
    }
}
