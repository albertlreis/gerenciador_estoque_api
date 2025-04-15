<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Na tabela produtos, adiciona a coluna `preco`
        Schema::table('produtos', function (Blueprint $table) {
            $table->decimal('preco', 10, 2)->nullable()->after('nome');
        });

        // Na tabela estoque:
        Schema::table('estoque', function (Blueprint $table) {
            // Adiciona o campo id_produto e a foreign key
            $table->unsignedInteger('id_produto')->nullable()->after('id_variacao');
            $table->foreign('id_produto')
                ->references('id')
                ->on('produtos')
                ->onDelete('cascade');

            // Remove a constraint e em seguida a coluna id_variacao
            $table->dropForeign(['id_variacao']);
            $table->dropColumn('id_variacao');
        });

        // Na tabela estoque_movimentacoes:
        Schema::table('estoque_movimentacoes', function (Blueprint $table) {
            // Adiciona o campo id_produto e a foreign key
            $table->unsignedInteger('id_produto')->nullable()->after('id_variacao');
            $table->foreign('id_produto')
                ->references('id')
                ->on('produtos')
                ->onDelete('cascade');

            // Remove a constraint e a coluna id_variacao
            $table->dropForeign(['id_variacao']);
            $table->dropColumn('id_variacao');
        });

        // Na tabela pedido_itens:
        Schema::table('pedido_itens', function (Blueprint $table) {
            // Adiciona o campo id_produto e a foreign key
            $table->unsignedInteger('id_produto')->nullable()->after('id_variacao');
            $table->foreign('id_produto')
                ->references('id')
                ->on('produtos')
                ->onDelete('cascade');

            // Remove a constraint e a coluna id_variacao
            $table->dropForeign(['id_variacao']);
            $table->dropColumn('id_variacao');
        });
    }

    public function down()
    {
        // Reverter alterações em pedido_itens:
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->dropForeign(['id_produto']);
            $table->dropColumn('id_produto');
            $table->unsignedInteger('id_variacao')->nullable()->after('id');
        });

        // Reverter alterações em estoque_movimentacoes:
        Schema::table('estoque_movimentacoes', function (Blueprint $table) {
            $table->dropForeign(['id_produto']);
            $table->dropColumn('id_produto');
            $table->unsignedInteger('id_variacao')->nullable()->after('id');
        });

        // Reverter alterações em estoque:
        Schema::table('estoque', function (Blueprint $table) {
            $table->dropForeign(['id_produto']);
            $table->dropColumn('id_produto');
            $table->unsignedInteger('id_variacao')->nullable()->after('id');
        });

        // Na tabela produtos, remove a coluna `preco`
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn('preco');
        });
    }
};
