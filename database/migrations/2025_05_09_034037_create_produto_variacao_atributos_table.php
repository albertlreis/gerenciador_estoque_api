<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela que armazena os atributos de uma variação.
     * Exemplo: cor = azul, tipo_metal = inox, etc.
     */
    public function up()
    {
        Schema::create('produto_variacao_atributos', function (Blueprint $table) {
            $table->increments('id'); // Identificador
            $table->unsignedInteger('id_variacao'); // Variação relacionada
            $table->string('atributo', 100); // Nome do atributo (ex: cor, tipo_madeira)
            $table->string('valor', 100); // Valor do atributo (ex: vermelho, carvalho)
            $table->timestamps();

            // Chave estrangeira para a variação
            $table->foreign('id_variacao')->references('id')->on('produto_variacoes')->onDelete('cascade');
            $table->unique(['id_variacao', 'atributo']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('produto_variacao_atributos');
    }
};
