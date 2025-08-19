<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('assistencia_arquivos', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('chamado_id');
            $table->unsignedInteger('item_id')->nullable();

            $table->string('tipo', 50)->nullable(); // foto_defeito, nota_remessa, orcamento, comprovante_envio, outro
            $table->string('path');
            $table->string('nome_original')->nullable();
            $table->unsignedBigInteger('tamanho')->nullable();
            $table->string('mime', 150)->nullable();

            $table->timestamps();

            $table->foreign('chamado_id')->references('id')->on('assistencia_chamados')->onDelete('cascade');
            $table->foreign('item_id')->references('id')->on('assistencia_chamado_itens')->onDelete('cascade');

            $table->index(['tipo']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('assistencia_arquivos');
    }
};
