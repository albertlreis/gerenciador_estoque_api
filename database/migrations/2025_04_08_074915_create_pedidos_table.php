<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('id_cliente')->nullable()->comment('ID do cliente que realizou o pedido');

            $table->foreignId('id_usuario')->comment('ID do usuário (vendedor) que registrou o pedido');
            $table->unsignedInteger('id_parceiro')->nullable()->comment('ID do parceiro vinculado ao pedido');

            $table->enum('tipo', ['venda', 'reposicao'])->default('venda')->comment('Tipo do pedido');
            $table->string('numero_externo', 50)->nullable()->unique()->comment('Número do pedido em sistema externo');

            $table->timestamp('data_pedido')->nullable()->comment('Data em que o pedido foi confirmado');
            $table->decimal('valor_total', 10, 2)->nullable()->comment('Valor total do pedido');
            $table->text('observacoes')->nullable()->comment('Observações adicionais do pedido');

            $table->unsignedSmallInteger('prazo_dias_uteis')->default(60);
            $table->date('data_limite_entrega')->nullable();

            $table->timestamps();

            $table->index('tipo');
            $table->index('data_limite_entrega');

            $table->foreign('id_cliente', 'pedidos_id_cliente_foreign')
                ->references('id')->on('clientes')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('id_usuario')
                ->references('id')->on('acesso_usuarios')
                ->cascadeOnDelete()
                ->onUpdate('restrict');

            $table->foreign('id_parceiro')
                ->references('id')->on('parceiros')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
