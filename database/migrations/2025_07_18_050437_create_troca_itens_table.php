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
        Schema::create('troca_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devolucao_item_id')->constrained('devolucao_itens')->cascadeOnDelete();
            $table->unsignedInteger('nova_variacao_id');
            $table->foreign('nova_variacao_id')->references('id')->on('produto_variacoes')->onDelete('cascade');
            $table->integer('quantidade');
            $table->decimal('preco_unitario', 10, 2);
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
        Schema::dropIfExists('troca_itens');
    }
};
