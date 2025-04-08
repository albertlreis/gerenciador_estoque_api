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
        Schema::create('atributo_valores', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_atributo');
            $table->string('valor', 255);
            $table->timestamps();

            $table->foreign('id_atributo')
                ->references('id')
                ->on('atributos')
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
        Schema::dropIfExists('atributo_valores');
    }
};
