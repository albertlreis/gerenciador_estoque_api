<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos_fabrica_itens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pedido_fabrica_id')
                ->constrained('pedidos_fabrica')
                ->cascadeOnDelete();

            $table->unsignedInteger('produto_variacao_id');
            $table->unsignedInteger('quantidade');

            // entregas parciais
            $table->unsignedInteger('quantidade_entregue')->default(0);

            // depósito previsto/selecionado (opcional)
            $table->unsignedInteger('deposito_id')->nullable();

            // vínculo opcional ao pedido de venda que originou o pedido de fábrica
            $table->unsignedInteger('pedido_venda_id')->nullable();

            $table->text('observacoes')->nullable();
            $table->timestamps();

            // índices
            $table->index(['pedido_fabrica_id', 'produto_variacao_id'], 'pfi_pf_var_idx');
            $table->index('deposito_id');
            $table->index('pedido_venda_id');

            // FKs (preservando histórico)
            $table->foreign('produto_variacao_id', 'pfi_variacao_fk')
                ->references('id')->on('produto_variacoes')
                ->onDelete('restrict')      // evita apagar histórico se alguém tentar deletar variação
                ->onUpdate('restrict');

            $table->foreign('deposito_id', 'pfi_deposito_fk')
                ->references('id')->on('depositos')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('pedido_venda_id', 'pfi_pedido_venda_fk')
                ->references('id')->on('pedidos')
                ->nullOnDelete()           // importante: não apagar item de fábrica se apagar pedido de venda
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos_fabrica_itens');
    }
};
