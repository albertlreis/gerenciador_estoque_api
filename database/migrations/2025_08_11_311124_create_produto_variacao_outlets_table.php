<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produto_variacao_outlets', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('produto_variacao_id');

            // agora é FK para tabela de motivos
            $table->unsignedBigInteger('motivo_id');

            $table->unsignedInteger('quantidade');
            $table->unsignedInteger('quantidade_restante');

            $table->unsignedInteger('usuario_id')->nullable();

            $table->timestamps();

            $table->index(['produto_variacao_id', 'quantidade_restante'], 'idx_pvo_variacao_restante');
            $table->index('motivo_id');

            $table->foreign('produto_variacao_id', 'pvo_variacao_fk')
                ->references('id')->on('produto_variacoes')
                ->cascadeOnDelete()
                ->onUpdate('restrict');

            $table->foreign('motivo_id', 'pvo_motivo_fk')
                ->references('id')->on('outlet_motivos')
                ->onDelete('restrict')
                ->onUpdate('restrict');

            $table->foreign('usuario_id', 'pvo_usuario_fk')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_variacao_outlets');
    }
};
