<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Criação da tabela 'pedido_itens'.
 * Armazena os produtos (variações) que compõem um pedido.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pedido_itens', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('id_pedido')->comment('Referência ao pedido');
            $table->unsignedInteger('id_variacao')->comment('Referência à variação do produto');

            $table->integer('quantidade')->comment('Quantidade da variação no pedido');
            $table->decimal('preco_unitario', 10, 2)->comment('Preço unitário no momento do pedido');
            $table->decimal('subtotal', 10, 2)->comment('Subtotal = quantidade * preço_unitário');

            $table->timestamps();

            $table->foreign('id_pedido')->references('id')->on('pedidos')->onDelete('cascade');
            $table->foreign('id_variacao')->references('id')->on('produto_variacoes')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pedido_itens');
    }
};
