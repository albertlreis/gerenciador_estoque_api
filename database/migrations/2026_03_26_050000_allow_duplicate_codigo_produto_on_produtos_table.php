<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::table('produtos', function (Blueprint $table) {
                $table->dropUnique('uq_produtos_codigo_produto');
            });
        } catch (\Throwable) {
            // Projeto atual não exige unicidade de codigo_produto.
        }
    }

    public function down(): void
    {
        // Intencionalmente sem rollback para UNIQUE.
    }
};
