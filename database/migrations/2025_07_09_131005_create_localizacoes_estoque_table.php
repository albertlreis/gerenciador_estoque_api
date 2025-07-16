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
        Schema::create('localizacoes_estoque', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('estoque_id');
            $table->string('corredor', 10)->nullable();
            $table->string('prateleira', 10)->nullable();
            $table->string('coluna', 10)->nullable();
            $table->string('nivel', 10)->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->foreign('estoque_id')
                ->references('id')
                ->on('estoque')
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
        Schema::dropIfExists('localizacoes_estoque');
    }
};
