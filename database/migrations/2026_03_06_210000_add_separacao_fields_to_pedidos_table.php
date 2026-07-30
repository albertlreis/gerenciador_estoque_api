<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('separacao_status', 20)->nullable()->after('data_limite_entrega');
            $table->timestamp('separado_em')->nullable()->after('separacao_status');
            $table->unsignedBigInteger('separado_por')->nullable()->after('separado_em');
            $table->timestamp('entregue_em')->nullable()->after('separado_por');
            $table->unsignedBigInteger('entregue_por')->nullable()->after('entregue_em');

            $table->index('separacao_status');
            $table->foreign('separado_por')->references('id')->on('acesso_usuarios')->nullOnDelete();
            $table->foreign('entregue_por')->references('id')->on('acesso_usuarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropForeign(['separado_por']);
            $table->dropForeign(['entregue_por']);
            $table->dropIndex(['separacao_status']);
            $table->dropColumn(['separacao_status', 'separado_em', 'separado_por', 'entregue_em', 'entregue_por']);
        });
    }
};
