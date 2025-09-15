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
        Schema::create('pedidos_fabrica', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['pendente', 'enviado', 'parcial', 'entregue', 'cancelado'])->default('pendente');
            $table->date('data_previsao_entrega')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos_fabrica');
    }
};
