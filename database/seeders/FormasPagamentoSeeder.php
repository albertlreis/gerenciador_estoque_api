<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Support\InitialData\InventoryInitialDataService;

class FormasPagamentoSeeder extends Seeder
{
    public function run(): void
    {
        app(InventoryInitialDataService::class)->seedFormasPagamento();
    }
}
