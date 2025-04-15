<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Estoque;

class EstoqueTableSeeder extends Seeder
{
    public function run()
    {
        // Exemplo: Produto 1 no Depósito 1 com 100 unidades
        Estoque::create([
            'id_produto'  => 1,
            'id_deposito' => 1,
            'quantidade'  => 100,
        ]);

        // Exemplo: Produto 2 no Depósito 1 com 50 unidades
        Estoque::create([
            'id_produto'  => 2,
            'id_deposito' => 1,
            'quantidade'  => 50,
        ]);

        // Exemplo: Produto 3 no Depósito 2 com 30 unidades
        Estoque::create([
            'id_produto'  => 3,
            'id_deposito' => 2,
            'quantidade'  => 30,
        ]);
    }
}
