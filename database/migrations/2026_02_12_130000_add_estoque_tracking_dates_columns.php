<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('estoque')) {
            return;
        }

        Schema::table('estoque', function (Blueprint $table) {
            if (!Schema::hasColumn('estoque', 'data_entrada_estoque_atual')) {
                $table->timestamp('data_entrada_estoque_atual')
                    ->nullable()
                    ->after('quantidade');
            }

            if (!Schema::hasColumn('estoque', 'ultima_venda_em')) {
                $table->timestamp('ultima_venda_em')
                    ->nullable()
                    ->after('data_entrada_estoque_atual');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('estoque')) {
            return;
        }

        Schema::table('estoque', function (Blueprint $table) {
            if (Schema::hasColumn('estoque', 'ultima_venda_em')) {
                $table->dropColumn('ultima_venda_em');
            }

            if (Schema::hasColumn('estoque', 'data_entrada_estoque_atual')) {
                $table->dropColumn('data_entrada_estoque_atual');
            }
        });
    }
};
