<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    /**
     * Tabela de variações de um produto.
     * Cada variação representa uma combinação específica com preço, referência e código de barras próprios.
     */
    public function up(): void
    {
        Schema::create('produto_variacoes', function (Blueprint $table) {
            $table->increments('id'); // Identificador da variação
            $table->unsignedInteger('produto_id'); // Produto ao qual pertence essa variação
            $table->string('referencia', 100)->unique(); // Referência única da variação
            $table->string('nome', 255)->nullable(); // Nome descritivo da variação (ex: "Preta - Inox")
            $table->decimal('preco', 10); // Preço de venda
            $table->decimal('custo', 10); // Custo de aquisição/fabricação
            $table->string('codigo_barras', 100)->nullable(); // Código de barras (EAN, GTIN, etc)
            $table->timestamps();

            // Chave estrangeira para produto base
            $table->foreign('produto_id')->references('id')->on('produtos')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('produto_variacoes');
    }
};
