<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        // FULLTEXT (para busca textual parcial / relevância)
        DB::statement(
            'ALTER TABLE `produto_variacoes` ADD FULLTEXT INDEX `ft_pv_referencia_nome` (`referencia`, `nome`)'
        );

        // BTREE (para consultas rápidas por prefixo: referencia LIKE "ABC%")
        DB::statement(
            'CREATE INDEX `idx_pv_referencia` ON `produto_variacoes` (`referencia`)'
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        // Reverte na ordem inversa, com nomes exatos
        DB::statement('DROP INDEX `idx_pv_referencia` ON `produto_variacoes`');
        DB::statement('ALTER TABLE `produto_variacoes` DROP INDEX `ft_pv_referencia_nome`');
    }
};
