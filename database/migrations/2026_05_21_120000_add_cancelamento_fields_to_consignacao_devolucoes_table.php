<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignacao_devolucoes', function (Blueprint $table) {
            $table->unsignedInteger('estoque_movimentacao_id')->nullable()->after('usuario_id');

            $table->unsignedInteger('deposito_id')->nullable()->after('estoque_movimentacao_id');
            $table->timestamp('cancelada_em')->nullable()->after('data_devolucao');
            $table->foreignId('cancelada_por')->nullable()->after('cancelada_em');
            $table->text('motivo_cancelamento')->nullable()->after('cancelada_por');

            $table->foreign('deposito_id')
                ->references('id')->on('depositos')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('estoque_movimentacao_id')
                ->references('id')->on('estoque_movimentacoes')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('cancelada_por')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('consignacao_devolucoes', function (Blueprint $table) {
            $table->dropForeign(['estoque_movimentacao_id']);
            $table->dropForeign(['deposito_id']);
            $table->dropForeign(['cancelada_por']);

            $table->dropColumn([
                'estoque_movimentacao_id',
                'deposito_id',
                'cancelada_em',
                'cancelada_por',
                'motivo_cancelamento',
            ]);
        });
    }
};
