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
        DB::statement('ALTER TABLE `estoque` DROP INDEX `idx_estoque_variacao_deposito`');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        DB::statement('CREATE INDEX `idx_estoque_variacao_deposito` ON `estoque` (`id_variacao`, `id_deposito`)');
    }
};
