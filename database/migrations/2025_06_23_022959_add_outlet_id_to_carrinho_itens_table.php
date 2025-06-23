<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Adiciona a coluna `outlet_id` à tabela `carrinho_itens`.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('carrinho_itens', function (Blueprint $table) {
            $table->unsignedBigInteger('outlet_id')
                ->nullable()
                ->after('id_variacao')
                ->comment('Referência ao outlet selecionado para o item');

            $table->foreign('outlet_id')
                ->references('id')
                ->on('produto_variacao_outlets')
                ->onDelete('set null');
        });
    }

    /**
     * Remove a coluna `outlet_id`.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('carrinho_itens', function (Blueprint $table) {
            $table->dropForeign(['outlet_id']);
            $table->dropColumn('outlet_id');
        });
    }
};
