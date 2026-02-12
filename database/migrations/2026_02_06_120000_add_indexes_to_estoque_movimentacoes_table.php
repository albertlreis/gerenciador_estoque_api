<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

//        Schema::table('estoque_movimentacoes', function (Blueprint $table) {
//            $table->index(['id_variacao', 'data_movimentacao'], 'ix_em_var_data');
//            $table->index(['id_variacao', 'tipo', 'data_movimentacao'], 'ix_em_var_tipo_data');
//            $table->index(['id_deposito_origem', 'data_movimentacao'], 'ix_em_dep_orig_data');
//            $table->index(['id_deposito_destino', 'data_movimentacao'], 'ix_em_dep_dest_data');
//            $table->index(['data_movimentacao', 'id'], 'ix_em_data_id');
//        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

//        Schema::table('estoque_movimentacoes', function (Blueprint $table) {
//            $table->dropIndex('ix_em_var_data');
//            $table->dropIndex('ix_em_var_tipo_data');
//            $table->dropIndex('ix_em_dep_orig_data');
//            $table->dropIndex('ix_em_dep_dest_data');
//            $table->dropIndex('ix_em_data_id');
//        });

        Schema::enableForeignKeyConstraints();
    }
};
