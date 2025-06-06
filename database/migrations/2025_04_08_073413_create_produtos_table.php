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
            $table->increments('id')->comment('Identificador do produto');
            $table->string('nome', 255)->comment('Nome do produto');
            $table->text('descricao')->nullable()->comment('Descrição opcional do produto');
            $table->unsignedInteger('id_categoria')->comment('Categoria do produto');
            $table->unsignedInteger('id_fornecedor')->nullable()->comment('Fornecedor do produto');
            $table->decimal('altura', 10, 2)->nullable()->comment('Altura em centímetros');
            $table->decimal('largura', 10, 2)->nullable()->comment('Largura em centímetros');
            $table->decimal('profundidade', 10, 2)->nullable()->comment('Profundidade em centímetros');
            $table->decimal('peso', 10, 2)->nullable()->comment('Peso em quilos');
            $table->boolean('ativo')->default(true)->comment('Produto está ativo no sistema');
            $table->timestamps();

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
