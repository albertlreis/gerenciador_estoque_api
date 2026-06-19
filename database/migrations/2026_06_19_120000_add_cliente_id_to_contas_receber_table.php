<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas_receber', function (Blueprint $table) {
            $table->unsignedInteger('cliente_id')->nullable()->after('pedido_id');
            $table->foreign('cliente_id', 'contas_receber_cliente_fk')
                ->references('id')
                ->on('clientes')
                ->nullOnDelete();
            $table->index('cliente_id', 'contas_receber_cliente_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contas_receber', function (Blueprint $table) {
            $table->dropForeign('contas_receber_cliente_fk');
            $table->dropIndex('contas_receber_cliente_idx');
            $table->dropColumn('cliente_id');
        });
    }
};
