<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->unsignedSmallInteger('prazo_dias_uteis')->default(60)->after('observacoes');
            $table->date('data_limite_entrega')->nullable()->after('prazo_dias_uteis');

            $table->index('data_limite_entrega');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropIndex(['data_limite_entrega']);
            $table->dropColumn(['prazo_dias_uteis','data_limite_entrega']);
        });
    }
};
