<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignacoes', function (Blueprint $table) {
            $table->unsignedInteger('pedido_item_id')->nullable()->after('pedido_id');
            $table->index('pedido_item_id', 'consignacoes_pedido_item_idx');

            $table->foreign('pedido_item_id', 'consignacoes_pedido_item_fk')
                ->references('id')->on('pedido_itens')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('consignacoes', function (Blueprint $table) {
            $table->dropForeign('consignacoes_pedido_item_fk');
            $table->dropIndex('consignacoes_pedido_item_idx');
            $table->dropColumn('pedido_item_id');
        });
    }
};
