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
    public function up(): void
    {
        Schema::create('pedido_itens', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('id_pedido')->comment('Referência ao pedido');

            // novo (carrinho)
            $table->unsignedInteger('id_carrinho_item')->nullable();

            $table->unsignedInteger('id_variacao')->comment('Referência à variação do produto');

            // novo (depósito sugerido/selecionado)
            $table->unsignedInteger('id_deposito')->nullable();

            $table->integer('quantidade')->comment('Quantidade da variação no pedido');

            // entrega pendente
            $table->boolean('entrega_pendente')->default(false);
            $table->timestamp('data_liberacao_entrega')->nullable();
            $table->text('observacao_entrega_pendente')->nullable();

            $table->decimal('preco_unitario', 10, 2)->comment('Preço unitário no momento do pedido');

            // ajuste: subtotal como valor monetário (10,2)
            $table->decimal('subtotal', 10, 2)->comment('Subtotal = quantidade * preço_unitário');

            $table->text('observacoes')->nullable();

            $table->timestamps();

            // índices
            $table->index(['id_pedido', 'id_carrinho_item'], 'pedido_itens_pedido_carrinho_idx');
            $table->index('id_deposito');

            // FKs
            $table->foreign('id_pedido')
                ->references('id')->on('pedidos')
                ->onDelete('cascade')
                ->onUpdate('restrict');

            $table->foreign('id_variacao')
                ->references('id')->on('produto_variacoes')
                ->onDelete('cascade')
                ->onUpdate('restrict');

            $table->foreign('id_deposito')
                ->references('id')->on('depositos')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_itens');
    }
};
