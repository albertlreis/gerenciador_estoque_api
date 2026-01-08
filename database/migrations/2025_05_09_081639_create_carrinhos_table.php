<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrinhos', function (Blueprint $table) {
            $table->increments('id');

            $table->enum('status', ['rascunho', 'finalizado', 'cancelado'])->default('rascunho');

            $table->foreignId('id_usuario')->comment('ID do usuário que está montando o carrinho');

            $table->unsignedInteger('id_cliente')->nullable()->comment('ID do cliente selecionado');
            $table->unsignedInteger('id_parceiro')->nullable()->comment('ID do parceiro selecionado');

            $table->timestamps();

            $table->index(['id_usuario', 'status'], 'carrinhos_usuario_status_idx');
            $table->index('id_cliente', 'carrinhos_cliente_idx');
            $table->index('id_parceiro', 'carrinhos_parceiro_idx');

            $table->foreign('id_usuario', 'carrinhos_usuario_fk')
                ->references('id')->on('acesso_usuarios')
                ->cascadeOnDelete()
                ->onUpdate('restrict');

            $table->foreign('id_cliente', 'carrinhos_cliente_fk')
                ->references('id')->on('clientes')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('id_parceiro', 'carrinhos_parceiro_fk')
                ->references('id')->on('parceiros')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrinhos');
    }
};
