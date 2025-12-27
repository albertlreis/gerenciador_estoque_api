<?php

namespace App\Domain\Financeiro\Contracts;

use Illuminate\Database\Eloquent\Model;

interface FinanceiroAuditoriaServiceContract
{
    public function log(string $acao, Model $entidade, ?array $antes = null, ?array $depois = null): void;
}
