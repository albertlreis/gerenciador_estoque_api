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
            ClientesSeeder::class,
            PedidosSeeder::class,
        ]);
    }
}
