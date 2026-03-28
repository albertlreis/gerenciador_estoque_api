<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Support\InitialData\InventoryInitialDataService;

/**
 * Semeia as áreas padrão solicitadas:
 * Assistência, Devolução, Tampos Avariados, Tampos Clientes, Avarias
 */
class AreasEstoqueSeeder extends Seeder
{
    public function run(): void
    {
        app(InventoryInitialDataService::class)->seedAreasEstoque();
    }
}
