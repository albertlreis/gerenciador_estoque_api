<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Support\InitialData\InventoryInitialDataService;

/**
 * Cria o Depósito "ASSISTÊNCIA" caso exista tabela de depósitos.
 * Seguro contra diferenças de schema.
 */
class AssistenciaDepositoSeeder extends Seeder
{
    public function run(): void
    {
        app(InventoryInitialDataService::class)->ensureAssistenciaDeposito();
    }
}
