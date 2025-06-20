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
    public function run(): void
    {
        $this->call([
            CategoriasSeeder::class,
            FornecedoresSeeder::class,
            ProdutosSeeder::class,
            ProdutoVariacoesSeeder::class,
            ProdutoVariacaoOutletSeeder::class,
            ProdutoImagensSeeder::class,
            DepositosSeeder::class,
            EstoqueSeeder::class,
            EstoqueMovimentacoesSeeder::class,
            ClientesSeeder::class,
            ParceirosSeeder::class,
            CarrinhosSeeder::class,
            PedidosSeeder::class,
            ConsignacoesSeeder::class,
            ConfiguracoesSeeder::class,
        ]);
    }
}
