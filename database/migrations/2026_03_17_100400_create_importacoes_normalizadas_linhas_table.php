<?php

use App\Enums\ImportacaoNormalizadaLinhaStatus;
use App\Enums\StatusRevisaoCadastro;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importacoes_normalizadas_linhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacao_id')->constrained('importacoes_normalizadas')->cascadeOnDelete();
            $table->unsignedInteger('produto_id_vinculado')->nullable();
            $table->unsignedInteger('variacao_id_vinculada')->nullable();
            $table->string('aba_origem', 120);
            $table->unsignedInteger('linha_planilha');
            $table->string('hash_linha', 64)->index();

            $table->json('dados_brutos')->nullable();
            $table->json('dados_normalizados')->nullable();

            $table->string('codigo', 120)->nullable();
            $table->string('codigo_origem', 120)->nullable();
            $table->string('codigo_modelo', 120)->nullable();
            $table->string('nome')->nullable();
            $table->string('nome_normalizado')->nullable();
            $table->string('nome_base_normalizado')->nullable();
            $table->string('categoria')->nullable();
            $table->string('categoria_normalizada')->nullable();
            $table->string('categoria_oficial')->nullable();
            $table->string('codigo_produto', 120)->nullable();
            $table->string('chave_produto')->nullable();
            $table->string('chave_produto_calculada')->nullable();
            $table->string('chave_variacao')->nullable();
            $table->string('chave_variacao_calculada')->nullable();
            $table->string('sku_interno', 120)->nullable();
            $table->boolean('conflito_codigo')->default(false);
            $table->string('regra_categoria', 50)->nullable();
            $table->decimal('dimensao_1', 10, 2)->nullable();
            $table->decimal('dimensao_2', 10, 2)->nullable();
            $table->decimal('dimensao_3', 10, 2)->nullable();
            $table->string('cor', 150)->nullable();
            $table->string('lado', 120)->nullable();
            $table->string('material_oficial', 180)->nullable();
            $table->string('acabamento_oficial', 180)->nullable();

            $table->integer('quantidade')->nullable();
            $table->string('status', 80)->nullable();
            $table->string('status_normalizado', 80)->nullable();
            $table->boolean('gera_estoque')->default(false);
            $table->string('motivo_sem_estoque', 255)->nullable();
            $table->string('localizacao')->nullable();
            $table->date('data_entrada')->nullable();
            $table->decimal('valor', 12, 2)->nullable();
            $table->decimal('custo', 12, 2)->nullable();
            $table->boolean('outlet')->default(false);
            $table->string('fornecedor')->nullable();

            $table->json('avisos')->nullable();
            $table->json('erros')->nullable();
            $table->json('divergencias')->nullable();
            $table->string('status_revisao', 40)->default(StatusRevisaoCadastro::NAO_REVISADO->value);
            $table->string('status_processamento', 50)->default(ImportacaoNormalizadaLinhaStatus::STAGED->value);
            $table->string('decisao_manual', 100)->nullable();
            $table->text('motivo_decisao_manual')->nullable();
            $table->timestamps();

            $table->foreign('produto_id_vinculado', 'import_norm_linhas_produto_fk')
                ->references('id')->on('produtos')
                ->nullOnDelete()
                ->onUpdate('restrict');
            $table->foreign('variacao_id_vinculada', 'import_norm_linhas_variacao_fk')
                ->references('id')->on('produto_variacoes')
                ->nullOnDelete()
                ->onUpdate('restrict');

            $table->unique(
                ['importacao_id', 'aba_origem', 'linha_planilha'],
                'uq_importacoes_normalizadas_linhas_posicao'
            );
            $table->index(['codigo_produto', 'sku_interno'], 'idx_import_norm_linhas_codigo_produto_sku');
            $table->index(['status_revisao', 'status_processamento'], 'idx_import_norm_linhas_revisao_processamento');
            $table->index('chave_produto', 'idx_import_norm_linhas_chave_produto');
            $table->index('chave_variacao', 'idx_import_norm_linhas_chave_variacao');
            $table->index('status_normalizado', 'idx_import_norm_linhas_status_normalizado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importacoes_normalizadas_linhas');
    }
};
