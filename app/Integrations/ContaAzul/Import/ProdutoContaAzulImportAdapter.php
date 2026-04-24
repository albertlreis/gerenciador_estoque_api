<?php

namespace App\Integrations\ContaAzul\Import;

use App\Integrations\ContaAzul\ContaAzulEntityType;

class ProdutoContaAzulImportAdapter extends AbstractContaAzulImportAdapter
{
    public function tipoEntidade(): string
    {
        return ContaAzulEntityType::PRODUTO;
    }

    public function stagingTable(): string
    {
        return 'stg_conta_azul_produtos';
    }

    protected function settingsKey(): string
    {
        return 'produto';
    }

    protected function pathKey(): string
    {
        return 'produtos';
    }

    protected function defaultPath(): string
    {
        return '/v1/produtos';
    }
}
