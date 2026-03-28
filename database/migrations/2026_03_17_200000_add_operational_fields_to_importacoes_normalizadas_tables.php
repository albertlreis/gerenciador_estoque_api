<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importacoes_normalizadas', function (Blueprint $table) {
            $table->json('preview_resumo')->nullable()->after('metricas');
            $table->json('relatorio_final')->nullable()->after('preview_resumo');
            $table->timestamp('confirmado_em')->nullable()->after('relatorio_final');
            $table->unsignedBigInteger('confirmado_por')->nullable()->after('confirmado_em');
            $table->timestamp('efetivado_em')->nullable()->after('confirmado_por');
            $table->unsignedBigInteger('efetivado_por')->nullable()->after('efetivado_em');
            $table->string('chave_execucao', 36)->nullable()->after('efetivado_por');

            $table->foreign('confirmado_por', 'import_norm_confirmado_por_fk')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->foreign('efetivado_por', 'import_norm_efetivado_por_fk')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->index('confirmado_em', 'idx_import_norm_confirmado_em');
            $table->index('efetivado_em', 'idx_import_norm_efetivado_em');
            $table->index('chave_execucao', 'idx_import_norm_chave_execucao');
        });

        Schema::table('importacoes_normalizadas_linhas', function (Blueprint $table) {
            $table->string('classificacao_acao', 80)->nullable()->after('status_processamento');
            $table->string('produto_acao', 40)->nullable()->after('classificacao_acao');
            $table->string('variacao_acao', 40)->nullable()->after('produto_acao');
            $table->string('estoque_acao', 40)->nullable()->after('variacao_acao');
            $table->boolean('gera_movimentacao')->default(false)->after('estoque_acao');
            $table->text('motivo_bloqueio')->nullable()->after('gera_movimentacao');
            $table->json('resultado_preview')->nullable()->after('motivo_bloqueio');
            $table->json('resultado_execucao')->nullable()->after('resultado_preview');
            $table->timestamp('efetivada_em')->nullable()->after('resultado_execucao');
            $table->unsignedInteger('movimentacao_id')->nullable()->after('efetivada_em');
            $table->text('erro_execucao')->nullable()->after('movimentacao_id');

            $table->foreign('movimentacao_id', 'import_norm_linhas_movimentacao_fk')
                ->references('id')->on('estoque_movimentacoes')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->index('classificacao_acao', 'idx_import_norm_linhas_classificacao');
            $table->index('efetivada_em', 'idx_import_norm_linhas_efetivada_em');
            $table->index('gera_movimentacao', 'idx_import_norm_linhas_gera_movimentacao');
        });
    }

    public function down(): void
    {
        Schema::table('importacoes_normalizadas_linhas', function (Blueprint $table) {
            $table->dropForeign('import_norm_linhas_movimentacao_fk');
            $table->dropIndex('idx_import_norm_linhas_classificacao');
            $table->dropIndex('idx_import_norm_linhas_efetivada_em');
            $table->dropIndex('idx_import_norm_linhas_gera_movimentacao');
            $table->dropColumn([
                'classificacao_acao',
                'produto_acao',
                'variacao_acao',
                'estoque_acao',
                'gera_movimentacao',
                'motivo_bloqueio',
                'resultado_preview',
                'resultado_execucao',
                'efetivada_em',
                'movimentacao_id',
                'erro_execucao',
            ]);
        });

        Schema::table('importacoes_normalizadas', function (Blueprint $table) {
            $table->dropForeign('import_norm_confirmado_por_fk');
            $table->dropForeign('import_norm_efetivado_por_fk');
            $table->dropIndex('idx_import_norm_confirmado_em');
            $table->dropIndex('idx_import_norm_efetivado_em');
            $table->dropIndex('idx_import_norm_chave_execucao');
            $table->dropColumn([
                'preview_resumo',
                'relatorio_final',
                'confirmado_em',
                'confirmado_por',
                'efetivado_em',
                'efetivado_por',
                'chave_execucao',
            ]);
        });
    }
};
