<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ConfiguracoesSeeder::class,
            FeriadosSeeder::class,
            FormasPagamentoSeeder::class,
            OutletTabelasBasicasSeeder::class,
            AssistenciaDefeitosSeeder::class,
            AreasEstoqueSeeder::class,
            LocalizacaoDimensoesSeeder::class,
            CategoriasSeeder::class,
            FornecedoresSeeder::class,
            DepositosSeeder::class,
            AssistenciaDepositoSeeder::class,
            ClientesSeeder::class,
            ParceirosSeeder::class,
            ParceiroContatosSeeder::class,
            AssistenciasSeeder::class,
            CentrosCustoSeeder::class,
            CategoriasFinanceirasSeeder::class,
            ContasFinanceirasSeeder::class,
            ProdutosSeeder::class,
            ProdutoVariacoesSeeder::class,
            EstoqueSeeder::class,
            LocalizacaoEstoqueSeeder::class,
        ]);
    }
}
