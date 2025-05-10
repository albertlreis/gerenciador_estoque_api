<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            CategoriasSeeder::class,
            DepositosTableSeeder::class,
            ProdutosSeeder::class,
            ProdutoVariacaoAtributosSeeder::class,
            ClientesSeeder::class,
            ParceiroSeeder::class,
            PedidosSeeder::class,
            EstoqueTableSeeder::class,
            EstoqueMovimentacoesTableSeeder::class,
            ProdutoVariacaoVinculosSeeder::class,
        ]);
    }
}
