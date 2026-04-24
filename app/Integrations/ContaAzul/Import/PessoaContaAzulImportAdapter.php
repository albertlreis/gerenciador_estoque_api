<?php

namespace App\Integrations\ContaAzul\Import;

use App\Integrations\ContaAzul\ContaAzulEntityType;

class PessoaContaAzulImportAdapter extends AbstractContaAzulImportAdapter
{
    public function tipoEntidade(): string
    {
        return ContaAzulEntityType::PESSOA;
    }

    public function stagingTable(): string
    {
        return 'stg_conta_azul_pessoas';
    }

    protected function settingsKey(): string
    {
        return 'pessoa';
    }

    protected function pathKey(): string
    {
        return 'pessoas';
    }

    protected function defaultPath(): string
    {
        return '/v1/pessoas';
    }
}
