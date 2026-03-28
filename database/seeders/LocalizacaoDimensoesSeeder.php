<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Support\InitialData\InventoryInitialDataService;

class LocalizacaoDimensoesSeeder extends Seeder
{
    public function run(): void
    {
        app(InventoryInitialDataService::class)->seedLocalizacaoDimensoes();
    }
}
