<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // 1. Na tabela produtos, adiciona a coluna `preco`
        Schema::table('produtos', function (Blueprint $table) {
            $table->decimal('preco', 10, 2)->nullable()->after('nome');
        });

        /*
         * Atualizando a tabela estoque:
         * - Adiciona a coluna id_produto.
         * - Cria a foreign key para produtos.
         * - Remove a foreign key e a coluna id_variacao.
         * - Remove temporariamente a foreign key de id_deposito (pois o índice único vai ser alterado).
         */
        Schema::table('estoque', function (Blueprint $table) {
            $table->unsignedInteger('id_produto')->nullable()->after('id_variacao');
            $table->foreign('id_produto')
                ->references('id')
                ->on('produtos')
                ->onDelete('cascade');
            // Remove a foreign key e a coluna id_variacao
            $table->dropForeign(['id_variacao']);
            $table->dropColumn('id_variacao');
            // Remover a fk de id_deposito (para podermos dropar o índice único)
            $table->dropForeign(['id_deposito']);
        });

        // 2. Dropar o índice único antigo que estava definido sobre (id_variacao, id_deposito)
        Schema::table('estoque', function (Blueprint $table) {
            // O índice foi criado com o nome "uq_estoque" na migration de criação.
            $table->dropUnique('uq_estoque');
        });

        // 3. Criar o novo índice único composto por (id_produto, id_deposito)
        Schema::table('estoque', function (Blueprint $table) {
            $table->unique(['id_produto', 'id_deposito'], 'estoque_uq_estoque');
        });

        // 4. Re-adicionar a foreign key para id_deposito na tabela estoque
        Schema::table('estoque', function (Blueprint $table) {
            $table->foreign('id_deposito')
                ->references('id')
                ->on('depositos')
                ->onDelete('cascade');
        });

        /*
         * Atualizando a tabela estoque_movimentacoes:
         * - Adiciona a coluna id_produto e sua foreign key.
         * - Remove a foreign key e a coluna id_variacao.
         */
        Schema::table('estoque_movimentacoes', function (Blueprint $table) {
            $table->unsignedInteger('id_produto')->nullable()->after('id_variacao');
            $table->foreign('id_produto')
                ->references('id')
                ->on('produtos')
                ->onDelete('cascade');

            $table->dropForeign(['id_variacao']);
            $table->dropColumn('id_variacao');
        });

        /*
         * Atualizando a tabela pedido_itens (mesma lógica se necessário):
         * - Adiciona a coluna id_produto e sua foreign key.
         * - Remove a foreign key e a coluna id_variacao.
         */
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->unsignedInteger('id_produto')->nullable()->after('id_variacao');
            $table->foreign('id_produto')
                ->references('id')
                ->on('produtos')
                ->onDelete('cascade');

            $table->dropForeign(['id_variacao']);
            $table->dropColumn('id_variacao');
        });
    }

    public function down()
    {
        // Reverter alterações na tabela pedido_itens:
        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->dropForeign(['id_produto']);
            $table->dropColumn('id_produto');
            $table->unsignedInteger('id_variacao')->nullable()->after('id');
        });

        // Reverter alterações na tabela estoque_movimentacoes:
        Schema::table('estoque_movimentacoes', function (Blueprint $table) {
            $table->dropForeign(['id_produto']);
            $table->dropColumn('id_produto');
            $table->unsignedInteger('id_variacao')->nullable()->after('id');
        });

        // Reverter alterações na tabela estoque:
        Schema::table('estoque', function (Blueprint $table) {
            // Remove a foreign key para id_deposito definida na etapa 4
            $table->dropForeign(['id_deposito']);

            // Remove o índice único composto
            $table->dropUnique('estoque_uq_estoque');

            // Remove a foreign key para id_produto e a coluna
            $table->dropForeign(['id_produto']);
            $table->dropColumn('id_produto');

            // Recria a coluna id_variacao
            $table->unsignedInteger('id_variacao')->nullable()->after('id');
            // Recria a foreign key para id_variacao
            $table->foreign('id_variacao')
                ->references('id')
                ->on('produto_variacoes')
                ->onDelete('cascade');

            // Recria a foreign key para id_deposito
            $table->foreign('id_deposito')
                ->references('id')
                ->on('depositos')
                ->onDelete('cascade');

            // Recria o índice único original sobre (id_variacao, id_deposito) com o nome "uq_estoque"
            $table->unique(['id_variacao', 'id_deposito'], 'uq_estoque');
        });

        // Na tabela produtos, remove a coluna `preco`
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn('preco');
        });
    }
};
