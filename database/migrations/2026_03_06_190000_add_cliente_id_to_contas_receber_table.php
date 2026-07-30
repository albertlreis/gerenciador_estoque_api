<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas_receber', function (Blueprint $table) {
            $table->unsignedInteger('cliente_id')->nullable()->after('pedido_id');
            $table->foreign('cliente_id')->references('id')->on('clientes')->nullOnDelete();
            $table->index(['cliente_id', 'data_vencimento'], 'contas_receber_cliente_vencimento_idx');
        });

        DB::table('contas_receber')
            ->join('pedidos', 'pedidos.id', '=', 'contas_receber.pedido_id')
            ->whereNull('contas_receber.cliente_id')
            ->update(['contas_receber.cliente_id' => DB::raw('pedidos.id_cliente')]);
    }

    public function down(): void
    {
        Schema::table('contas_receber', function (Blueprint $table) {
            $table->dropForeign(['cliente_id']);
            $table->dropIndex('contas_receber_cliente_vencimento_idx');
            $table->dropColumn('cliente_id');
        });
    }
};
