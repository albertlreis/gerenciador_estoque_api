<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela de produtos base (ex: "Mesa de Jantar").
     * Os dados dimensionais são fixos por produto.
     */
    public function up(): void
    {
        Schema::create('produtos', function (Blueprint $table) {
            $table->increments('id'); // Identificador do produto
            $table->string('nome', 255); // Nome do produto
            $table->text('descricao')->nullable(); // Descrição opcional
            $table->unsignedInteger('id_categoria'); // Categoria do produto
            $table->unsignedInteger('id_fornecedor')->nullable(); // Fornecedor do produto
            $table->decimal('altura', 10, 2)->default(0); // Altura em centímetros
            $table->decimal('largura', 10, 2)->default(0); // Largura em centímetros
            $table->decimal('profundidade', 10, 2)->default(0); // Profundidade em centímetros
            $table->decimal('peso', 10, 2)->default(0); // Peso em quilos
            $table->boolean('ativo')->default(true); // Produto está ativo no sistema?
            $table->timestamps();

            // Chaves estrangeiras
            $table->foreign('id_categoria')->references('id')->on('categorias')->onDelete('cascade');
            $table->foreign('id_fornecedor')->references('id')->on('fornecedores')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('produtos');
    }
};
