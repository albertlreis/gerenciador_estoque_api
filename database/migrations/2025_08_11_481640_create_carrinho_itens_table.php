<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Itens do carrinho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrinho_itens', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('id_carrinho')->comment('Referência ao carrinho');
            $table->unsignedInteger('id_variacao')->comment('Referência à variação de produto');

            // Outlet selecionado (produto_variacao_outlets.id = BIGINT UNSIGNED)
            $table->unsignedBigInteger('outlet_id')->nullable()
                ->comment('Referência ao outlet selecionado para o item');

            // Depósito de saída (pode ser definido depois)
            $table->unsignedInteger('id_deposito')->nullable()
                ->comment('Referência ao depósito de saída do produto');

            $table->unsignedInteger('quantidade')->comment('Quantidade da variação no carrinho');
            $table->decimal('preco_unitario', 10, 2)->comment('Preço unitário no momento');
            $table->decimal('subtotal', 10, 2)->comment('Subtotal do item');

            $table->timestamps();

            // Índices
            $table->index('id_carrinho', 'carrinho_itens_carrinho_idx');
            $table->index('id_variacao', 'carrinho_itens_variacao_idx');
            $table->index('id_deposito', 'carrinho_itens_deposito_idx');
            $table->index('outlet_id', 'carrinho_itens_outlet_idx');

            // (Opcional) Ajuda a evitar duplicidades por combinação
            // Se vocês SEMPRE querem somar quantidade, descomente:
            // $table->unique(['id_carrinho','id_variacao','id_deposito','outlet_id'], 'uq_carrinho_item_combo');

            // FKs
            $table->foreign('id_carrinho', 'carrinho_itens_carrinho_fk')
                ->references('id')->on('carrinhos')
                ->cascadeOnDelete()
                ->onUpdate('restrict');

            $table->foreign('id_variacao', 'carrinho_itens_variacao_fk')
                ->references('id')->on('produto_variacoes')
                ->cascadeOnDelete() // carrinho é temporário; ok
                ->onUpdate('restrict');

            $table->foreign('id_deposito', 'carrinho_itens_deposito_fk')
                ->references('id')->on('depositos')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('outlet_id', 'carrinho_itens_outlet_fk')
                ->references('id')->on('produto_variacao_outlets')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrinho_itens');
    }
};
