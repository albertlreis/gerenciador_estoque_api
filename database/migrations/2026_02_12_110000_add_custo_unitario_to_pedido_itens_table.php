<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pedido_itens', 'custo_unitario')) {
            return;
        }

        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->decimal('custo_unitario', 10, 2)
                ->nullable()
                ->after('preco_unitario')
                ->comment('Custo unitario no momento do pedido');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('pedido_itens', 'custo_unitario')) {
            return;
        }

        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->dropColumn('custo_unitario');
        });
    }
};
