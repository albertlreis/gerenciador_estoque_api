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
        Schema::create('atributo_variacoes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_variacao');
            $table->unsignedInteger('id_atributo_valor');
            $table->timestamps();

            $table->foreign('id_variacao')
                ->references('id')
                ->on('produto_variacoes')
                ->onDelete('cascade');
            $table->foreign('id_atributo_valor')
                ->references('id')
                ->on('atributo_valores')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('atributo_variacoes');
    }
};
