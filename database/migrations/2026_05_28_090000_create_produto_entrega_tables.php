<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produto_entrega_itens', function (Blueprint $table) {
            $table->id();

            $table->string('tipo_origem', 40);
            $table->unsignedBigInteger('origem_id')->nullable();

            $table->unsignedInteger('pedido_id')->nullable();
            $table->unsignedInteger('pedido_item_id')->nullable();
            $table->unsignedBigInteger('pedido_fabrica_item_id')->nullable();
            $table->unsignedBigInteger('consignacao_id')->nullable();
            $table->unsignedInteger('assistencia_item_id')->nullable();
            $table->unsignedBigInteger('devolucao_item_id')->nullable();

            $table->unsignedInteger('id_variacao');
            $table->unsignedInteger('quantidade_total')->default(0);
            $table->unsignedInteger('quantidade_reservada')->default(0);
            $table->unsignedInteger('quantidade_recebida')->default(0);
            $table->unsignedInteger('quantidade_expedida')->default(0);
            $table->unsignedInteger('quantidade_entregue')->default(0);

            $table->unsignedInteger('id_deposito_origem')->nullable();
            $table->unsignedInteger('id_deposito_destino')->nullable();

            $table->string('status', 40)->index();
            $table->date('previsao_entrega')->nullable();
            $table->text('bloqueio_motivo')->nullable();
            $table->timestamps();

            $table->index(['tipo_origem', 'origem_id'], 'pei_origem_idx');
            $table->index(['pedido_id', 'status'], 'pei_pedido_status_idx');
            $table->index(['id_variacao', 'status'], 'pei_variacao_status_idx');
            $table->index(['id_deposito_origem', 'status'], 'pei_deposito_origem_status_idx');
            $table->index('pedido_item_id', 'pei_pedido_item_idx');
            $table->unique('pedido_fabrica_item_id', 'pei_fabrica_item_unique');
            $table->index('consignacao_id', 'pei_consignacao_idx');
            $table->unique('assistencia_item_id', 'pei_assistencia_item_unique');
            $table->unique('devolucao_item_id', 'pei_devolucao_item_unique');

            $table->foreign('pedido_id')
                ->references('id')->on('pedidos')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('pedido_item_id')
                ->references('id')->on('pedido_itens')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('pedido_fabrica_item_id')
                ->references('id')->on('pedidos_fabrica_itens')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('consignacao_id')
                ->references('id')->on('consignacoes')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('assistencia_item_id')
                ->references('id')->on('assistencia_chamado_itens')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('devolucao_item_id')
                ->references('id')->on('devolucao_itens')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('id_variacao')
                ->references('id')->on('produto_variacoes')
                ->restrictOnDelete()
                ->onUpdate('restrict');

            $table->foreign('id_deposito_origem')
                ->references('id')->on('depositos')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('id_deposito_destino')
                ->references('id')->on('depositos')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });

        Schema::create('produto_entrega_eventos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('produto_entrega_item_id')
                ->constrained('produto_entrega_itens')
                ->cascadeOnDelete();

            $table->string('tipo_evento', 50);
            $table->unsignedInteger('quantidade')->default(0);
            $table->unsignedInteger('id_deposito_origem')->nullable();
            $table->unsignedInteger('id_deposito_destino')->nullable();
            $table->unsignedInteger('estoque_reserva_id')->nullable();
            $table->unsignedInteger('estoque_movimentacao_id')->nullable();
            $table->foreignId('usuario_id')->nullable();
            $table->text('observacao')->nullable();
            $table->json('metadata_json')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->timestamps();

            $table->index(['produto_entrega_item_id', 'tipo_evento'], 'pee_item_tipo_idx');
            $table->index(['tipo_evento', 'created_at'], 'pee_tipo_created_idx');
            $table->index('estoque_reserva_id', 'pee_reserva_idx');
            $table->index('estoque_movimentacao_id', 'pee_movimentacao_idx');

            $table->foreign('id_deposito_origem')
                ->references('id')->on('depositos')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('id_deposito_destino')
                ->references('id')->on('depositos')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('estoque_reserva_id')
                ->references('id')->on('estoque_reservas')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('estoque_movimentacao_id')
                ->references('id')->on('estoque_movimentacoes')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('usuario_id')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_entrega_eventos');
        Schema::dropIfExists('produto_entrega_itens');
    }
};
