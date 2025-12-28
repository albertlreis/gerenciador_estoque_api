<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela de histórico de entregas de itens de pedido de fábrica.
     */
    public function up(): void
    {
        Schema::create('pedido_fabrica_entregas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pedido_fabrica_id')
                ->constrained('pedidos_fabrica')
                ->cascadeOnDelete();

            $table->foreignId('pedido_fabrica_item_id')
                ->constrained('pedidos_fabrica_itens')
                ->cascadeOnDelete();

            $table->unsignedInteger('deposito_id')->nullable();
            $table->unsignedInteger('quantidade');

            // padronizado p/ bater com acesso_usuarios.id
            $table->unsignedInteger('usuario_id')->nullable();

            $table->text('observacao')->nullable();
            $table->timestamps();

            $table->index(['pedido_fabrica_id', 'created_at'], 'pfent_pf_created_idx');
            $table->index(['pedido_fabrica_item_id', 'created_at'], 'pfent_item_created_idx');

            $table->foreign('deposito_id', 'pfent_deposito_fk')
                ->references('id')->on('depositos')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('usuario_id', 'pfent_usuario_fk')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_fabrica_entregas');
    }
};
