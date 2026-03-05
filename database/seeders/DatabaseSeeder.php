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
            // P0: referências e pré-requisitos de FK
            ConfiguracoesSeeder::class,
            FeriadosSeeder::class,
            FormasPagamentoSeeder::class,
            OutletTabelasBasicasSeeder::class,
            AssistenciaDefeitosSeeder::class,
            CategoriasSeeder::class,
            FornecedoresSeeder::class,
            DepositosSeeder::class,
            ClientesSeeder::class,
            ParceirosSeeder::class,
            ParceiroContatosSeeder::class,
            AreasEstoqueSeeder::class,
            LocalizacaoDimensoesSeeder::class,
            CentrosCustoSeeder::class,
            CategoriasFinanceirasSeeder::class,
            ContasFinanceirasSeeder::class,

            // P1: dados mínimos para fluxo principal de estoque
            ProdutosSeeder::class,
            ProdutoVariacoesSeeder::class,
            EstoqueSeeder::class,
            LocalizacaoEstoqueSeeder::class,
        ]);
    }
}
