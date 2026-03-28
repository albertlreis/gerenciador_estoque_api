<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Support\InitialData\InventoryInitialDataService;

/**
 * Seed do Catálogo de Defeitos.
 */
class AssistenciaDefeitosSeeder extends Seeder
{
    public function run(): void
    {
        app(InventoryInitialDataService::class)->seedAssistenciaDefeitos();
    }
}
