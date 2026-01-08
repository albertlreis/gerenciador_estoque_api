<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estoque_movimentacoes', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('id_variacao');
            $table->unsignedInteger('id_deposito_origem')->nullable();
            $table->unsignedInteger('id_deposito_destino')->nullable();

            $table->foreignId('id_usuario')->nullable();

            $table->char('lote_id', 36)->nullable();
            $table->string('ref_type', 80)->nullable();
            $table->unsignedInteger('ref_id')->nullable();

            $table->unsignedInteger('pedido_id')->nullable();
            $table->unsignedInteger('pedido_item_id')->nullable();
            $table->unsignedInteger('reserva_id')->nullable();

            $table->string('tipo', 50);
            $table->integer('quantidade');
            $table->text('observacao')->nullable();
            $table->timestamp('data_movimentacao')->nullable();

            $table->timestamps();

            $table->index('lote_id');
            $table->index(['ref_type', 'ref_id']);
            $table->index('pedido_id');
            $table->index('pedido_item_id');
            $table->index('reserva_id');

            $table->foreign('id_variacao')
                ->references('id')->on('produto_variacoes')
                ->cascadeOnDelete()
                ->onUpdate('restrict');

            $table->foreign('id_deposito_origem')
                ->references('id')->on('depositos')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('id_deposito_destino')
                ->references('id')->on('depositos')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('id_usuario')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('pedido_id', 'estoque_mov_pedido_fk')
                ->references('id')->on('pedidos')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('pedido_item_id', 'estoque_mov_pedido_item_fk')
                ->references('id')->on('pedido_itens')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('reserva_id', 'estoque_mov_reserva_fk')
                ->references('id')->on('estoque_reservas')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estoque_movimentacoes');
    }
};
