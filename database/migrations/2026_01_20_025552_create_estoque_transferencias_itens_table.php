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
    public function up(): void
    {
        Schema::create('estoque_transferencia_itens', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('transferencia_id');
            $table->unsignedInteger('id_variacao');
            $table->unsignedInteger('quantidade');

            // snapshot da localização no depósito de ORIGEM (para imprimir depois mesmo se mudar)
            $table->string('corredor', 50)->nullable();
            $table->string('prateleira', 50)->nullable();
            $table->string('nivel', 50)->nullable();

            $table->timestamps();

            $table->foreign('transferencia_id')->references('id')->on('estoque_transferencias')->onDelete('cascade');
            $table->foreign('id_variacao')->references('id')->on('produto_variacoes');

            $table->unique(['transferencia_id', 'id_variacao']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('estoque_transferencia_itens');
    }
};
