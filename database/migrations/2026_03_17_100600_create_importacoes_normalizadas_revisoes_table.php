<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importacoes_normalizadas_revisoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacao_id')->constrained('importacoes_normalizadas')->cascadeOnDelete();
            $table->foreignId('linha_id')->nullable()->constrained('importacoes_normalizadas_linhas')->nullOnDelete();
            $table->foreignId('conflito_id')->nullable()->constrained('importacoes_normalizadas_conflitos')->nullOnDelete();
            $table->unsignedInteger('produto_id')->nullable();
            $table->unsignedInteger('variacao_id')->nullable();
            $table->string('status_anterior', 40)->nullable();
            $table->string('status_novo', 40)->nullable();
            $table->string('decisao', 100);
            $table->text('motivo')->nullable();
            $table->json('detalhes')->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->timestamps();

            $table->foreign('produto_id', 'import_norm_revisoes_produto_fk')
                ->references('id')->on('produtos')
                ->nullOnDelete()
                ->onUpdate('restrict');
            $table->foreign('variacao_id', 'import_norm_revisoes_variacao_fk')
                ->references('id')->on('produto_variacoes')
                ->nullOnDelete()
                ->onUpdate('restrict');
            $table->foreign('usuario_id', 'import_norm_revisoes_usuario_fk')
                ->references('id')->on('acesso_usuarios')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->index(['importacao_id', 'created_at'], 'idx_import_norm_revisoes_importacao_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importacoes_normalizadas_revisoes');
    }
};
