<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financeiro_auditorias', function (Blueprint $table) {
            $table->id();

            $table->string('acao', 60);
            $table->string('entidade_type', 190);
            $table->unsignedBigInteger('entidade_id');

            $table->json('antes_json')->nullable();
            $table->json('depois_json')->nullable();

            $table->unsignedInteger('usuario_id')->nullable()->index();
            $table->foreign('usuario_id')->references('id')->on('acesso_usuarios')->nullOnDelete();

            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['entidade_type', 'entidade_id']);
            $table->index(['acao']);
            $table->index(['created_at'], 'ix_fin_audit_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financeiro_auditorias');
    }
};
