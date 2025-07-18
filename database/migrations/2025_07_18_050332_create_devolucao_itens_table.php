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
        Schema::create('devolucao_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devolucao_id')->constrained('devolucoes')->cascadeOnDelete();
            $table->unsignedInteger('pedido_item_id');
            $table->foreign('pedido_item_id')->references('id')->on('pedido_itens')->onDelete('cascade');

            $table->integer('quantidade');
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
        Schema::dropIfExists('devolucao_itens');
    }
};
