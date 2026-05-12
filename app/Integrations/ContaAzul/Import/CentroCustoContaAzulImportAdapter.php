<?php

namespace App\Integrations\ContaAzul\Import;

use App\Integrations\ContaAzul\ContaAzulEntityType;

class CentroCustoContaAzulImportAdapter extends AbstractContaAzulImportAdapter
{
    public function tipoEntidade(): string
    {
        return ContaAzulEntityType::CENTRO_CUSTO;
    }

    public function stagingTable(): string
    {
        return 'stg_conta_azul_centros_custo';
    }

    protected function settingsKey(): string
    {
        return 'centro_custo';
    }

    protected function pathKey(): string
    {
        return 'centros_custo';
    }

    protected function defaultPath(): string
    {
        return '/v1/centro-de-custo';
    }
}
