<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('pedidos_fabrica_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_fabrica_id')->constrained('pedidos_fabrica')->cascadeOnDelete();
            $table->unsignedInteger('produto_variacao_id');
            $table->foreign('produto_variacao_id')
                ->references('id')
                ->on('produto_variacoes')
                ->onDelete('cascade');
            $table->integer('quantidade');
            $table->unsignedInteger('pedido_venda_id')->nullable();
            $table->foreign('pedido_venda_id')
                ->references('id')
                ->on('pedidos')
                ->onDelete('cascade');

            $table->text('observacoes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos_fabrica_itens');
    }
};
