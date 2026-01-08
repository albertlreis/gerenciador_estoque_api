<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistencia_chamado_logs', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('chamado_id');
            $table->unsignedInteger('item_id')->nullable();

            $table->string('status_de', 50)->nullable();
            $table->string('status_para', 50)->nullable();

            $table->text('mensagem')->nullable();
            $table->json('meta_json')->nullable();

            $table->foreignId('usuario_id')->nullable();

            $table->timestamps();

            $table->foreign('chamado_id')->references('id')->on('assistencia_chamados')->onDelete('cascade');
            $table->foreign('item_id')->references('id')->on('assistencia_chamado_itens')->onDelete('cascade');

            $table->foreign('usuario_id')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->index(['chamado_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistencia_chamado_logs');
    }
};
