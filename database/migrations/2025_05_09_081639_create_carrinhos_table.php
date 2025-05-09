<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


/**
 * Criação da tabela 'carrinhos'.
 * Representa o carrinho de compras temporário de um usuário logado (vendedor).
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
        Schema::create('carrinhos', function (Blueprint $table) {
            $table->increments('id');

            // Usuário logado (vendedor)
            $table->unsignedInteger('id_usuario')->comment('ID do usuário que está montando o carrinho');

            // Referência opcional ao cliente do pedido
            $table->unsignedInteger('id_cliente')->nullable()->comment('ID do cliente selecionado');

            $table->timestamps();

            $table->foreign('id_usuario')->references('id')->on('acesso_usuarios')->onDelete('cascade');
            $table->foreign('id_cliente')->references('id')->on('clientes')->onDelete('set null');
        });

        Schema::create('carrinho_itens', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('id_carrinho')->comment('Referência ao carrinho');
            $table->unsignedInteger('id_variacao')->comment('Referência à variação de produto');
            $table->integer('quantidade')->comment('Quantidade da variação no carrinho');
            $table->decimal('preco_unitario', 10, 2)->comment('Preço unitário no momento');
            $table->decimal('subtotal', 10, 2)->comment('Subtotal do item');

            $table->timestamps();

            $table->foreign('id_carrinho')->references('id')->on('carrinhos')->onDelete('cascade');
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
        Schema::dropIfExists('carrinhos');
    }
};
