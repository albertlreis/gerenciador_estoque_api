<?php

namespace App\Integrations\Bancos\Contracts;

use App\Models\ContaFinanceira;
use Carbon\CarbonInterface;

interface BankStatementProvider
{
    /**
     * @return array<string,mixed>
     */
    public function fetchStatement(ContaFinanceira $conta, CarbonInterface $start, CarbonInterface $end): array;
}
