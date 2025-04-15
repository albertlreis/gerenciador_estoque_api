<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Deposito;

class DepositosTableSeeder extends Seeder
{
    public function run()
    {
        Deposito::create([
            'nome'     => 'Depósito Central',
            'endereco' => 'Rua Principal, 123 - Centro',
        ]);

        Deposito::create([
            'nome'     => 'Depósito Secundário',
            'endereco' => 'Avenida Secundária, 456 - Bairro',
        ]);
    }
}
