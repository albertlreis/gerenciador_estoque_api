<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        Schema::create('consignacoes', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('pedido_id');
            $table->unsignedInteger('produto_variacao_id');
            $table->unsignedInteger('deposito_id');

            $table->integer('quantidade');
            $table->date('data_envio');
            $table->date('prazo_resposta');
            $table->timestamp('data_resposta')->nullable();
            $table->enum('status', ['pendente', 'comprado', 'devolvido'])->default('pendente');

            $table->timestamps();

            $table->foreign('pedido_id')->references('id')->on('pedidos')->onDelete('cascade');
            $table->foreign('produto_variacao_id')->references('id')->on('produto_variacoes')->onDelete('cascade');
            $table->foreign('deposito_id')->references('id')->on('depositos')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        Schema::dropIfExists('consignacoes');
        DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
    }
};
