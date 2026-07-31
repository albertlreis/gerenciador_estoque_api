<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The canonical schema change is the later 2026_06_19 migration.
        // This older migration remains as a safe backfill for environments
        // where the later migration was already recorded.
        if (! Schema::hasTable('contas_receber') || ! Schema::hasColumn('contas_receber', 'cliente_id')) {
            return;
        }

        DB::table('contas_receber')
            ->join('pedidos', 'pedidos.id', '=', 'contas_receber.pedido_id')
            ->whereNull('contas_receber.cliente_id')
            ->update(['contas_receber.cliente_id' => DB::raw('pedidos.id_cliente')]);
    }

    public function down(): void
    {
        // This migration only backfills an existing column.
    }
};
