<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_fabrica_status_historicos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pedido_fabrica_id')
                ->constrained('pedidos_fabrica')
                ->cascadeOnDelete();

            $table->enum('status', ['pendente', 'enviado', 'parcial', 'entregue', 'cancelado']);

            // padronizado p/ bater com acesso_usuarios.id (normalmente unsignedInteger)
            $table->unsignedInteger('usuario_id')->nullable();

            $table->text('observacao')->nullable();
            $table->timestamps();

            $table->index(['pedido_fabrica_id', 'created_at'], 'pfsh_pf_created_idx');
            $table->index('status');

            $table->foreign('usuario_id', 'pfsh_usuario_fk')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_fabrica_status_historicos');
    }
};
