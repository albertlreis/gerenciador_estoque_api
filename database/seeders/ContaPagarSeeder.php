<?php

namespace Database\Seeders;

use App\Models\ContaPagar;
use Illuminate\Database\Seeder;

class ContaPagarSeeder extends Seeder
{
    public function run(): void
    {
        ContaPagar::factory()->count(30)->create();
    }
}
