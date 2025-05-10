<?php

namespace Database\Seeders;

use Exception;
use Illuminate\Database\Seeder;
use App\Models\Estoque;
use App\Models\ProdutoVariacao;

class EstoqueTableSeeder extends Seeder
{
    public function run()
    {
        $depositos = [1, 2];
        $variacoes = ProdutoVariacao::doesntHave('estoque')->inRandomOrder()->take(15)->get();

        if ($variacoes->count() < 15) {
            throw new Exception('É necessário pelo menos 15 variações de produto para popular o estoque.');
        }

        foreach ($variacoes as $index => $variacao) {
            Estoque::create([
                'id_variacao' => $variacao->id,
                'id_deposito' => $depositos[$index % 2],
                'quantidade' => rand(10, 200),
            ]);
        }
    }
}
