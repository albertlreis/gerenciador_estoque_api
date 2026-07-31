<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pedido_reconciliacoes', function (Blueprint $table) {
            $table->id();
            // `pedidos.id` is an unsigned INT in the legacy Sierra schema.
            // Keep the foreign key type aligned so this migration applies on MySQL.
            $table->unsignedInteger('pedido_id');
            $table->foreign('pedido_id')->references('id')->on('pedidos');
            $table->unsignedBigInteger('usuario_id');
            $table->string('idempotency_key', 120)->unique();
            $table->string('fonte_verdade', 40);
            $table->text('justificativa');
            $table->text('evidencia');
            $table->json('snapshot_json');
            $table->string('status', 30)->default('aprovada');
            $table->timestamp('aplicada_em')->nullable();
            $table->timestamps();
            $table->index(['pedido_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_reconciliacoes');
    }
};
