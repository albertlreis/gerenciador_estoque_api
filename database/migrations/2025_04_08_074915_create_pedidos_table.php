<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Criação da tabela 'pedidos'.
 * Um pedido é realizado por um cliente e pode ser intermediado por um parceiro/vendedor.
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
        Schema::create('pedidos', function (Blueprint $table) {
            $table->increments('id');

            // Relacionamento com cliente (obrigatório)
            $table->unsignedInteger('id_cliente')->comment('ID do cliente que realizou o pedido');

            // Relacionamento com usuário (vendedor, obrigatório)
            $table->unsignedInteger('id_usuario')->comment('ID do usuário (vendedor) que registrou o pedido');

            // Relacionamento com parceiro (arquiteto, designer, opcional)
            $table->unsignedInteger('id_parceiro')->nullable()->comment('ID do parceiro vinculado ao pedido');

            $table->string('numero_externo', 50)->nullable()->unique()->comment('Número do pedido em sistema externo');

            $table->timestamp('data_pedido')->nullable()->comment('Data em que o pedido foi confirmado');
            $table->decimal('valor_total', 10, 2)->nullable()->comment('Valor total do pedido');
            $table->text('observacoes')->nullable()->comment('Observações adicionais do pedido');
            $table->timestamps();

            $table->foreign('id_cliente')->references('id')->on('clientes')->onDelete('cascade');
            $table->foreign('id_usuario')->references('id')->on('acesso_usuarios')->onDelete('cascade');
            $table->foreign('id_parceiro')->references('id')->on('parceiros')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pedidos');
    }
};
