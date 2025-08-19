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
            ConfiguracoesSeeder::class,
            FeriadosSeeder::class,
            CategoriasSeeder::class,
            FornecedoresSeeder::class,
            ProdutosSeeder::class,
            ProdutoVariacoesSeeder::class,
            OutletTabelasBasicasSeeder::class,
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
            LocalizacaoEstoqueSeeder::class,
            PedidosFabricaSeeder::class,
            AssistenciasSeeder::class,
            AssistenciaDefeitosSeeder::class,
            AssistenciaDepositoSeeder::class,
            AssistenciaDemoSeeder::class,
        ]);
    }
}
