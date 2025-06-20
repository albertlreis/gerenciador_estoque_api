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
    public function up(): void
    {
        Schema::create('consignacao_devolucoes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignacao_id');
            $table->unsignedInteger('usuario_id')->comment('ID do usuário (vendedor) que registrou o pedido');
            $table->integer('quantidade');
            $table->text('observacoes')->nullable();
            $table->timestamp('data_devolucao')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamps();

            $table->foreign('consignacao_id')->references('id')->on('consignacoes')->onDelete('cascade');
            $table->foreign('usuario_id')->references('id')->on('acesso_usuarios');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('consignacao_devolucoes');
    }
};
