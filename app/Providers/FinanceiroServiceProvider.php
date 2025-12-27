<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Domain\Financeiro\Contracts\FinanceiroLedgerServiceContract;
use App\Domain\Financeiro\Contracts\FinanceiroAuditoriaServiceContract;
use App\Services\FinanceiroLedgerService;
use App\Services\FinanceiroAuditoriaService;

class FinanceiroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FinanceiroLedgerServiceContract::class, FinanceiroLedgerService::class);
        $this->app->bind(FinanceiroAuditoriaServiceContract::class, FinanceiroAuditoriaService::class);
    }
}
