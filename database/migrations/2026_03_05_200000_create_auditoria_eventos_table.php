<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria_eventos', function (Blueprint $table) {
            $table->id();
            $table->string('module', 80);
            $table->string('action', 40);
            $table->string('label', 255);

            $table->string('actor_type', 120)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 255)->nullable();

            $table->string('auditable_type', 190)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            $table->string('route', 255)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('origin', 30)->default('API');
            $table->json('metadata_json')->nullable();

            $table->timestamps();

            $table->index(['module', 'action'], 'idx_auditoria_eventos_mod_acao');
            $table->index(['auditable_type', 'auditable_id'], 'idx_auditoria_eventos_auditable');
            $table->index('actor_id', 'idx_auditoria_eventos_actor');
            $table->index('created_at', 'idx_auditoria_eventos_created_at');

            $table->foreign('actor_id')
                ->references('id')
                ->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_eventos');
    }
};
