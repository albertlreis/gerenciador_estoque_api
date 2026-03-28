<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importacoes_normalizadas_linhas', function (Blueprint $table) {
            $table->index(
                ['importacao_id', 'status_normalizado'],
                'idx_import_norm_linhas_importacao_status_norm'
            );
            $table->index(
                ['importacao_id', 'classificacao_acao'],
                'idx_import_norm_linhas_importacao_classificacao'
            );
            $table->index(
                ['importacao_id', 'status_processamento', 'status_revisao'],
                'idx_import_norm_linhas_importacao_proc_revisao'
            );
            $table->index(
                ['importacao_id', 'gera_estoque'],
                'idx_import_norm_linhas_importacao_gera_estoque'
            );
        });

        Schema::table('importacoes_normalizadas_conflitos', function (Blueprint $table) {
            $table->index(
                ['importacao_id', 'status_revisao', 'severidade'],
                'idx_import_norm_conflitos_importacao_revisao_severidade'
            );
        });
    }

    public function down(): void
    {
        Schema::table('importacoes_normalizadas_conflitos', function (Blueprint $table) {
            $table->dropIndex('idx_import_norm_conflitos_importacao_revisao_severidade');
        });

        Schema::table('importacoes_normalizadas_linhas', function (Blueprint $table) {
            $table->dropIndex('idx_import_norm_linhas_importacao_status_norm');
            $table->dropIndex('idx_import_norm_linhas_importacao_classificacao');
            $table->dropIndex('idx_import_norm_linhas_importacao_proc_revisao');
            $table->dropIndex('idx_import_norm_linhas_importacao_gera_estoque');
        });
    }
};
