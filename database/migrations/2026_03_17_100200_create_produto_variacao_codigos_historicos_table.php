<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produto_variacao_codigos_historicos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('produto_variacao_id');
            $table->string('codigo', 120)->nullable();
            $table->string('codigo_origem', 120)->nullable();
            $table->string('codigo_modelo', 120)->nullable();
            $table->string('hash_conteudo', 64);
            $table->string('fonte', 80)->nullable();
            $table->string('aba_origem', 120)->nullable();
            $table->string('observacoes', 255)->nullable();
            $table->boolean('principal')->default(false);
            $table->timestamps();

            $table->foreign('produto_variacao_id', 'pv_codigos_historicos_variacao_fk')
                ->references('id')->on('produto_variacoes')
                ->cascadeOnDelete()
                ->onUpdate('restrict');

            $table->unique(
                ['produto_variacao_id', 'hash_conteudo'],
                'uq_pv_codigos_historicos_hash'
            );
            $table->index('codigo', 'idx_pv_codigos_historicos_codigo');
            $table->index('codigo_origem', 'idx_pv_codigos_historicos_codigo_origem');
            $table->index('codigo_modelo', 'idx_pv_codigos_historicos_codigo_modelo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_variacao_codigos_historicos');
    }
};
