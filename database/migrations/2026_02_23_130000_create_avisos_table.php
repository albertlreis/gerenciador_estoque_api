<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avisos', function (Blueprint $table) {
            $table->increments('id');
            $table->string('titulo', 255);
            $table->text('conteudo');
            $table->enum('status', ['rascunho', 'publicado', 'arquivado'])->default('rascunho');
            $table->enum('prioridade', ['normal', 'importante'])->default('normal');
            $table->boolean('pinned')->default(false);
            $table->dateTime('publicar_em')->nullable();
            $table->dateTime('expirar_em')->nullable();
            $table->unsignedBigInteger('criado_por_usuario_id')->nullable();
            $table->unsignedBigInteger('atualizado_por_usuario_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'publicar_em', 'expirar_em'], 'idx_avisos_status_publicar_expirar');
            $table->index(['pinned', 'prioridade', 'created_at'], 'idx_avisos_pinned_prioridade_created');

            $table->foreign('criado_por_usuario_id', 'fk_avisos_criado_por_usuario')
                ->references('id')
                ->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('atualizado_por_usuario_id', 'fk_avisos_atualizado_por_usuario')
                ->references('id')
                ->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos');
    }
};

