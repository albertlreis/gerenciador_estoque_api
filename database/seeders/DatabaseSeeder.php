<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run(): void
    {
        if (!app()->environment('production')) {
            Storage::disk('public')->deleteDirectory('produtos');
            Storage::disk('public')->makeDirectory('produtos');
        }

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
            AreasEstoqueSeeder::class,
            LocalizacaoEstoqueSeeder::class,
            PedidosFabricaSeeder::class,
            AssistenciasSeeder::class,
            AssistenciaDefeitosSeeder::class,
            AssistenciaDepositoSeeder::class,
            AssistenciaDemoSeeder::class,
        ]);
    }
}
