<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->unsignedInteger('id_fornecedor')->nullable()->after('id_parceiro');
            $table->index('id_fornecedor', 'pedidos_id_fornecedor_idx');

            $table->foreign('id_fornecedor', 'pedidos_id_fornecedor_foreign')
                ->references('id')->on('fornecedores')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropForeign('pedidos_id_fornecedor_foreign');
            $table->dropIndex('pedidos_id_fornecedor_idx');
            $table->dropColumn('id_fornecedor');
        });
    }
};
