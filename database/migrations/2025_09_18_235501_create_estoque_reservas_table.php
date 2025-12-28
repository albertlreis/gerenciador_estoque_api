<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estoque_reservas', function (Blueprint $table) {
            $table->increments('id');

            // padronizado para bater com produto_variacoes/depositos/pedidos (normalmente increments/unsignedInteger)
            $table->unsignedInteger('id_variacao');
            $table->unsignedInteger('id_deposito')->nullable();
            $table->unsignedInteger('pedido_id')->nullable();
            $table->unsignedInteger('pedido_item_id')->nullable();
            $table->unsignedInteger('id_usuario')->nullable();

            $table->integer('quantidade');
            $table->unsignedInteger('quantidade_consumida')->default(0);

            $table->enum('status', ['ativa','consumida','cancelada','expirada'])
                ->default('ativa');

            $table->string('motivo', 100)->nullable();
            $table->timestamp('data_expira')->nullable();

            $table->timestamps();

            // Índices
            $table->index(['id_variacao', 'id_deposito']);
            $table->index('status');
            $table->index(['pedido_id', 'pedido_item_id']);

            // FKs
            $table->foreign('id_variacao', 'estoque_reservas_id_variacao_fk')
                ->references('id')->on('produto_variacoes')
                ->onDelete('cascade')
                ->onUpdate('restrict');

            $table->foreign('id_deposito', 'estoque_reservas_id_deposito_fk')
                ->references('id')->on('depositos')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('pedido_id', 'estoque_reservas_pedido_fk')
                ->references('id')->on('pedidos')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('pedido_item_id', 'estoque_reservas_pedido_item_fk')
                ->references('id')->on('pedido_itens')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('id_usuario', 'estoque_reservas_usuario_fk')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estoque_reservas');
    }
};
