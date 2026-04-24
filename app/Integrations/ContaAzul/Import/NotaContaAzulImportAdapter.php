<?php

namespace App\Integrations\ContaAzul\Import;

use App\Integrations\ContaAzul\ContaAzulEntityType;

class NotaContaAzulImportAdapter extends AbstractContaAzulImportAdapter
{
    public function tipoEntidade(): string
    {
        return ContaAzulEntityType::NOTA;
    }

    public function stagingTable(): string
    {
        return 'stg_conta_azul_notas';
    }

    protected function settingsKey(): string
    {
        return 'nota';
    }

    protected function pathKey(): string
    {
        return 'notas_list';
    }

    protected function defaultPath(): string
    {
        return '/v1/notas-fiscais';
    }
}
