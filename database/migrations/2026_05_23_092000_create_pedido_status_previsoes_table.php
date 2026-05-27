<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_status_previsoes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pedido_id');
            $table->string('status', 50);
            $table->date('data_prevista')->nullable();
            $table->foreignId('usuario_id')->nullable();
            $table->timestamps();

            $table->unique(['pedido_id', 'status'], 'pedido_status_previsoes_unique');
            $table->foreign('pedido_id')->references('id')->on('pedidos')->cascadeOnDelete();
            $table->foreign('usuario_id')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_status_previsoes');
    }
};
