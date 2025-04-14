<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Adiciona a coluna `preco` à tabela produtos
        Schema::table('produtos', function (Blueprint $table) {
            $table->decimal('preco', 10, 2)->nullable()->after('nome');
        });

        // Em estoque, adiciona o campo id_produto e a foreign key
        Schema::table('estoque', function (Blueprint $table) {
            $table->unsignedInteger('id_produto')->nullable()->after('id_variacao');
            $table->foreign('id_produto')
                ->references('id')
                ->on('produtos')
                ->onDelete('cascade');
        });

        // Em estoque_movimentacoes, adiciona o campo id_produto e a foreign key
        Schema::table('estoque_movimentacoes', function (Blueprint $table) {
            $table->unsignedInteger('id_produto')->nullable()->after('id_variacao');
            $table->foreign('id_produto')
                ->references('id')
                ->on('produtos')
                ->onDelete('cascade');
        });

        // Em pedido_itens, adiciona o campo id_produto e a foreign key
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->unsignedInteger('id_produto')->nullable()->after('id_variacao');
            $table->foreign('id_produto')
                ->references('id')
                ->on('produtos')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->dropForeign(['id_produto']);
            $table->dropColumn('id_produto');
        });

        Schema::table('estoque_movimentacoes', function (Blueprint $table) {
            $table->dropForeign(['id_produto']);
            $table->dropColumn('id_produto');
        });

        Schema::table('estoque', function (Blueprint $table) {
            $table->dropForeign(['id_produto']);
            $table->dropColumn('id_produto');
        });

        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn('preco');
        });
    }
};
