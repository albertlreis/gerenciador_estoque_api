<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->string('tipo', 50);
            $table->text('descricao')->nullable();
            $table->string('local')->nullable();
            $table->dateTime('inicio_em');
            $table->dateTime('fim_em');
            $table->unsignedInteger('criado_por')->nullable();
            $table->string('google_event_id')->nullable();
            $table->string('google_calendar_id')->nullable();
            $table->string('google_sync_status', 30)->nullable();
            $table->dateTime('google_last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['inicio_em', 'fim_em'], 'idx_eventos_periodo');
            $table->index('criado_por', 'idx_eventos_criado_por');
        });

        Schema::create('evento_participantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('eventos')->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->boolean('obrigatorio')->default(false);
            $table->string('status_confirmacao', 30)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['evento_id', 'user_id'], 'uq_evento_participantes_evento_usuario');
            $table->index('user_id', 'idx_evento_participantes_usuario');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_participantes');
        Schema::dropIfExists('eventos');
    }
};
