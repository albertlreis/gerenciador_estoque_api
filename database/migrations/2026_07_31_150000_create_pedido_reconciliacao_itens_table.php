<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pedido_reconciliacao_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_reconciliacao_id')
                ->constrained('pedido_reconciliacoes')
                ->cascadeOnDelete();
            $table->unsignedInteger('pedido_item_id')->nullable();
            $table->unsignedBigInteger('produto_entrega_item_id')->nullable();
            $table->unsignedInteger('estoque_movimentacao_id')->nullable();
            $table->unsignedBigInteger('produto_entrega_evento_id')->nullable();
            $table->string('acao', 40);
            $table->string('classificacao_original', 40)->nullable();
            $table->unsignedInteger('id_variacao');
            $table->unsignedInteger('id_deposito')->nullable();
            $table->unsignedInteger('quantidade');
            $table->unsignedInteger('saldo_sistema_antes')->nullable();
            $table->unsignedInteger('saldo_fisico_confirmado')->nullable();
            $table->unsignedInteger('saldo_sistema_depois')->nullable();
            $table->string('status', 30)->default('aprovada');
            $table->json('resultado_json')->nullable();
            $table->timestamp('aplicada_em')->nullable();
            $table->timestamp('estornada_em')->nullable();
            $table->timestamps();

            $table->foreign('pedido_item_id')
                ->references('id')->on('pedido_itens')
                ->nullOnDelete()->onUpdate('restrict');
            $table->foreign('produto_entrega_item_id')
                ->references('id')->on('produto_entrega_itens')
                ->nullOnDelete()->onUpdate('restrict');
            $table->foreign('estoque_movimentacao_id')
                ->references('id')->on('estoque_movimentacoes')
                ->nullOnDelete()->onUpdate('restrict');
            $table->foreign('produto_entrega_evento_id')
                ->references('id')->on('produto_entrega_eventos')
                ->nullOnDelete()->onUpdate('restrict');
            $table->foreign('id_variacao')
                ->references('id')->on('produto_variacoes')
                ->restrictOnDelete()->onUpdate('restrict');
            $table->foreign('id_deposito')
                ->references('id')->on('depositos')
                ->nullOnDelete()->onUpdate('restrict');

            $table->unique(
                ['pedido_reconciliacao_id', 'pedido_item_id', 'acao'],
                'pri_reconciliacao_item_acao_unique'
            );
            $table->index(['status', 'acao'], 'pri_status_acao_idx');
            $table->index(['id_variacao', 'id_deposito'], 'pri_variacao_deposito_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_reconciliacao_itens');
    }
};
