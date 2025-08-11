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
        Schema::create('estoque_movimentacoes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_variacao');
            $table->unsignedInteger('id_deposito_origem')->nullable();
            $table->unsignedInteger('id_deposito_destino')->nullable();
            $table->unsignedInteger('id_usuario')->nullable();
            $table->string('tipo', 50);
            $table->integer('quantidade');
            $table->text('observacao')->nullable();
            $table->timestamp('data_movimentacao')->nullable();
            $table->timestamps();

            $table->foreign('id_variacao')
                ->references('id')
                ->on('produto_variacoes')
                ->onDelete('cascade');
            $table->foreign('id_deposito_origem')
                ->references('id')
                ->on('depositos')
                ->onDelete('cascade');
            $table->foreign('id_deposito_destino')
                ->references('id')
                ->on('depositos')
                ->onDelete('cascade');
            $table->foreign('id_usuario')
                ->references('id')
                ->on('usuarios')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('estoque_movimentacoes');
    }
};
