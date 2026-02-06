<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_importacoes', function (Blueprint $table) {
            $table->id();

            $table->string('arquivo_nome')->nullable();
            $table->char('arquivo_hash', 64);

            $table->string('numero_externo', 50)->nullable();

            $table->unsignedInteger('pedido_id')->nullable();

            $table->unsignedBigInteger('usuario_id')->nullable();

            $table->string('status', 30)->default('extraido');
            $table->text('erro')->nullable();
            $table->json('dados_json')->nullable();

            $table->timestamps();

            $table->unique('arquivo_hash', 'uq_pedido_import_hash');
            $table->index('numero_externo', 'idx_pedido_import_numero');
            $table->index('pedido_id', 'idx_pedido_import_pedido');

            $table->foreign('pedido_id')
                ->references('id')->on('pedidos')
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
        Schema::dropIfExists('pedido_importacoes');
    }
};
