<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_importacao_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_importacao_id')->nullable()
                ->constrained('pedido_importacoes')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->unsignedInteger('pedido_id');
            $table->unsignedInteger('pedido_item_id')->nullable();
            $table->unsignedBigInteger('produto_id')->nullable();
            $table->unsignedBigInteger('produto_variacao_id')->nullable();
            $table->string('acao', 30)->default('vinculado');
            $table->json('dados_importados_json')->nullable();
            $table->json('dados_confirmados_json')->nullable();
            $table->timestamps();

            $table->index('pedido_importacao_id', 'idx_pedido_import_itens_importacao');
            $table->index('pedido_id', 'idx_pedido_import_itens_pedido');
            $table->index('produto_variacao_id', 'idx_pedido_import_itens_variacao');

            $table->foreign('pedido_id')
                ->references('id')->on('pedidos')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('pedido_item_id')
                ->references('id')->on('pedido_itens')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_importacao_itens');
    }
};
