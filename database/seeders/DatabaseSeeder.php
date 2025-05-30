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
            FornecedoresSeeder::class,
            ProdutosSeeder::class,
            ProdutoVariacaoAtributosSeeder::class,
            ProdutoImagensSeeder::class,
            ClientesSeeder::class,
            ParceiroSeeder::class,
            PedidosSeeder::class,
            EstoqueTableSeeder::class,
            EstoqueMovimentacoesTableSeeder::class,
            ProdutoVariacaoVinculosSeeder::class,
            ConsignacaoSeeder::class,
            PedidoStatusHistoricoSeeder::class,
        ]);
    }
}
