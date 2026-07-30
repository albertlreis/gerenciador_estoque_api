<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aviso_leituras', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('aviso_id');
            $table->unsignedBigInteger('usuario_id');
            $table->dateTime('lido_em');
            $table->timestamps();

            $table->unique(['aviso_id', 'usuario_id'], 'uq_aviso_leituras_aviso_usuario');
            $table->index(['usuario_id', 'lido_em'], 'idx_aviso_leituras_usuario_lido_em');

            $table->foreign('aviso_id', 'fk_aviso_leituras_aviso')
                ->references('id')
                ->on('avisos')
                ->cascadeOnDelete()
                ->onUpdate('restrict');

            $table->foreign('usuario_id', 'fk_aviso_leituras_usuario')
                ->references('id')
                ->on('acesso_usuarios')
                ->cascadeOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aviso_leituras');
    }
};

