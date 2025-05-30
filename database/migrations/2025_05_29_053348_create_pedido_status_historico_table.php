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
        Schema::create('pedido_status_historico', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pedido_id');
            $table->string('status', 50);
            $table->timestamp('data_status')->nullable();
            $table->unsignedInteger('usuario_id')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->foreign('pedido_id')->references('id')->on('pedidos')->onDelete('cascade');
            $table->foreign('usuario_id')->references('id')->on('acesso_usuarios')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pedido_status_historico');
    }
};
