<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produto_variacoes', function (Blueprint $table) {
            $table->boolean('ativo')->default(true)->after('status_revisao');
            $table->text('motivo_desativacao')->nullable()->after('ativo');
            $table->index('ativo', 'idx_produto_variacoes_ativo');
        });
    }

    public function down(): void
    {
        Schema::table('produto_variacoes', function (Blueprint $table) {
            $table->dropIndex('idx_produto_variacoes_ativo');
            $table->dropColumn(['ativo', 'motivo_desativacao']);
        });
    }
};
