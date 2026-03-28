<?php

use App\Enums\ImportacaoNormalizadaConflitoSeveridade;
use App\Enums\StatusRevisaoCadastro;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importacoes_normalizadas_conflitos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacao_id')->constrained('importacoes_normalizadas')->cascadeOnDelete();
            $table->foreignId('linha_id')->nullable()->constrained('importacoes_normalizadas_linhas')->nullOnDelete();
            $table->string('tipo', 80);
            $table->string('campo', 80)->nullable();
            $table->string('severidade', 20)->default(ImportacaoNormalizadaConflitoSeveridade::CONFLITO->value);
            $table->text('descricao');
            $table->text('valor_informado')->nullable();
            $table->text('valor_calculado')->nullable();
            $table->json('detalhes')->nullable();
            $table->string('status_revisao', 40)->default(StatusRevisaoCadastro::PENDENTE_REVISAO->value);
            $table->string('decisao_manual', 100)->nullable();
            $table->text('motivo_decisao_manual')->nullable();
            $table->unsignedBigInteger('resolvido_por')->nullable();
            $table->timestamp('resolvido_em')->nullable();
            $table->timestamps();

            $table->foreign('resolvido_por', 'import_norm_conflitos_resolvido_por_fk')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->index(['importacao_id', 'tipo'], 'idx_import_norm_conflitos_importacao_tipo');
            $table->index(['status_revisao', 'severidade'], 'idx_import_norm_conflitos_status_severidade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importacoes_normalizadas_conflitos');
    }
};
