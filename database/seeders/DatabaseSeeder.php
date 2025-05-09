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
            ProdutosSeeder::class,
            ProdutoVariacoesSeeder::class,
            ProdutoVariacaoAtributosSeeder::class,
            ClientesSeeder::class,
            ParceiroSeeder::class,
            PedidosSeeder::class,
            DepositosTableSeeder::class,
            EstoqueTableSeeder::class,
            EstoqueMovimentacoesTableSeeder::class,
            ProdutoVariacaoVinculosSeeder::class,
        ]);
    }
}
