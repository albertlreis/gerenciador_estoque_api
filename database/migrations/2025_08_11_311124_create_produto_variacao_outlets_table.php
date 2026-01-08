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

            $table->foreignId('motivo_id')
                ->constrained('outlet_motivos')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->unsignedInteger('quantidade');
            $table->unsignedInteger('quantidade_restante');

            $table->foreignId('usuario_id')->nullable()
                ->constrained('acesso_usuarios')
                ->nullOnDelete()
                ->restrictOnUpdate();

            $table->timestamps();

            $table->index(['produto_variacao_id', 'quantidade_restante'], 'idx_pvo_variacao_restante');
            $table->index('motivo_id');
            $table->index('usuario_id');

            $table->foreign('produto_variacao_id', 'pvo_variacao_fk')
                ->references('id')->on('produto_variacoes')
                ->cascadeOnDelete()
                ->onUpdate('restrict');

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_variacao_outlets');
    }
};
