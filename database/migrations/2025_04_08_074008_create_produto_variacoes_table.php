<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela de variações de um produto.
     */
    public function up(): void
    {
        Schema::create('produto_variacoes', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('produto_id');
            $table->string('referencia', 100);
            $table->string('nome', 255)->nullable();

            // corrigindo escala monetária
            $table->decimal('preco', 10, 2)->nullable();
            $table->decimal('custo', 10, 2)->nullable();

            $table->string('codigo_barras', 100)->nullable();
            $table->timestamps();

            $table->index('produto_id', 'idx_pv_produto');
            $table->index('referencia', 'idx_pv_referencia');

            $table->foreign('produto_id', 'pv_produto_fk')
                ->references('id')->on('produtos')
                ->cascadeOnDelete()
                ->onUpdate('restrict');
        });

        // FULLTEXT (busca textual por referência/nome)
        DB::statement(
            'ALTER TABLE `produto_variacoes` ADD FULLTEXT INDEX `ft_pv_referencia_nome` (`referencia`, `nome`)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_variacoes');
    }
};
