<?php

namespace App\Console\Commands;

use App\Support\InitialData\InventoryInitialDataService;
use Illuminate\Console\Command;

class SetupInitialDataCommand extends Command
{
    protected $signature = 'app:setup-initial-data';

    protected $description = 'Garante a carga inicial obrigatória da API de estoque.';

    public function handle(InventoryInitialDataService $service): int
    {
        $service->runBootstrap(function (string $label) {
            $this->line(" - {$label}");
        });

        $this->info('Carga inicial concluída sem duplicar registros obrigatórios.');

        return self::SUCCESS;
    }
}
