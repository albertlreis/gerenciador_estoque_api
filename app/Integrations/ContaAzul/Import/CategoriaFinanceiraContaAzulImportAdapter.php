<?php

namespace App\Integrations\ContaAzul\Import;

use App\Integrations\ContaAzul\ContaAzulEntityType;

class CategoriaFinanceiraContaAzulImportAdapter extends AbstractContaAzulImportAdapter
{
    public function tipoEntidade(): string
    {
        return ContaAzulEntityType::CATEGORIA_FINANCEIRA;
    }

    public function stagingTable(): string
    {
        return 'stg_conta_azul_categorias_financeiras';
    }

    protected function settingsKey(): string
    {
        return 'categoria_financeira';
    }

    protected function pathKey(): string
    {
        return 'categorias_financeiras';
    }

    protected function defaultPath(): string
    {
        return '/v1/categorias';
    }
}
