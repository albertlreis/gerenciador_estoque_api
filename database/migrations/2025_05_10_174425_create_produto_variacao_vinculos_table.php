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
        Schema::create('produto_variacao_vinculos', function (Blueprint $table) {
            $table->id();
            $table->string('descricao_xml')->unique();
            $table->unsignedInteger('produto_variacao_id');
            $table->foreign('produto_variacao_id')
                ->references('id')
                ->on('produto_variacoes')
                ->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('produto_variacao_vinculos');
    }
};
