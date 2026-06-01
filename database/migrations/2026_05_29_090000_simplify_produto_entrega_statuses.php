<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('produto_entrega_itens', 'em_revisao')) {
            Schema::table('produto_entrega_itens', function (Blueprint $table) {
                $table->boolean('em_revisao')->default(false)->after('status');
            });
        }

        DB::table('produto_entrega_itens')
            ->where('status', 'bloqueado_revisao')
            ->update([
                'status' => 'aguardando_estoque',
                'em_revisao' => true,
            ]);

        DB::table('produto_entrega_itens')
            ->whereIn('status', ['recebido_parcial'])
            ->update(['status' => 'aguardando_estoque']);

        DB::table('produto_entrega_itens')
            ->whereIn('status', [
                'pronto_expedicao',
                'expedido',
                'expedido_parcial',
                'entregue_parcial',
            ])
            ->update(['status' => 'reservado']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('produto_entrega_itens', 'em_revisao')) {
            Schema::table('produto_entrega_itens', function (Blueprint $table) {
                $table->dropColumn('em_revisao');
            });
        }
    }
};
