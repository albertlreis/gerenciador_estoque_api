<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produto_variacao_outlets', function (Blueprint $table) {
            $table->unsignedInteger('produto_variacao_imagem_id')
                ->nullable()
                ->after('usuario_id');

            $table->index('produto_variacao_imagem_id', 'idx_pvo_imagem_variacao');
            $table->foreign('produto_variacao_imagem_id', 'pvo_imagem_variacao_fk')
                ->references('id')
                ->on('produto_variacao_imagens')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('produto_variacao_outlets', function (Blueprint $table) {
            $table->dropForeign('pvo_imagem_variacao_fk');
            $table->dropIndex('idx_pvo_imagem_variacao');
            $table->dropColumn('produto_variacao_imagem_id');
        });
    }
};
