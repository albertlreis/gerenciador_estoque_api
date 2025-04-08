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
        Schema::create('estoque', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_variacao');
            $table->unsignedInteger('id_deposito');
            $table->integer('quantidade');
            $table->timestamps();

            $table->foreign('id_variacao')
                ->references('id')
                ->on('produto_variacoes')
                ->onDelete('cascade');
            $table->foreign('id_deposito')
                ->references('id')
                ->on('depositos')
                ->onDelete('cascade');
            $table->unique(['id_variacao', 'id_deposito'], 'uq_estoque');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('estoque');
    }
};
