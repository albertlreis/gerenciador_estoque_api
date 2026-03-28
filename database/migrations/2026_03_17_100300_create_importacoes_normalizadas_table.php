<?php

use App\Enums\ImportacaoNormalizadaStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importacoes_normalizadas', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 80)->default('planilha_sierra_normalizada');
            $table->string('arquivo_nome');
            $table->string('arquivo_hash', 64)->index();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('status', 40)->default(ImportacaoNormalizadaStatus::RECEBIDA->value);
            $table->json('abas_processadas')->nullable();
            $table->unsignedInteger('linhas_total')->default(0);
            $table->unsignedInteger('linhas_staged')->default(0);
            $table->unsignedInteger('linhas_com_conflito')->default(0);
            $table->unsignedInteger('linhas_pendentes_revisao')->default(0);
            $table->unsignedInteger('linhas_com_erro')->default(0);
            $table->json('metricas')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_importacoes_normalizadas_status_created_at');
            $table->foreign('usuario_id', 'importacoes_normalizadas_usuario_fk')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importacoes_normalizadas');
    }
};
