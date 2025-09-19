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
        Schema::create('estoque_reservas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_variacao');
            $table->unsignedBigInteger('id_deposito')->nullable(); // depósito específico (opcional)
            $table->unsignedBigInteger('pedido_id')->nullable();   // vínculo opcional ao pedido
            $table->integer('quantidade');
            $table->string('motivo', 100)->nullable();             // ex.: 'pedido_sem_movimentacao'
            $table->timestamp('data_expira')->nullable();          // se quiser política de expiração
            $table->timestamps();

            $table->index(['id_variacao', 'id_deposito']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('estoque_reservas');
    }
};
