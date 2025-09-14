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
        Schema::create('localizacao_valores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('localizacao_id');
            $table->unsignedBigInteger('dimensao_id');
            $table->string('valor', 30)->nullable();
            $table->timestamps();

            $table->foreign('localizacao_id')->references('id')->on('localizacoes_estoque')->onDelete('cascade');
            $table->foreign('dimensao_id')->references('id')->on('localizacao_dimensoes')->onDelete('cascade');
            $table->unique(['localizacao_id', 'dimensao_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('localizacao_valores');
    }
};
