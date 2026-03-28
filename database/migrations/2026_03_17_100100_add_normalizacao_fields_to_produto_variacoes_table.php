<?php

use App\Enums\StatusRevisaoCadastro;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produto_variacoes', function (Blueprint $table) {
            $table->string('sku_interno', 120)->nullable()->after('referencia');
            $table->string('chave_variacao', 255)->nullable()->after('sku_interno');
            $table->decimal('dimensao_1', 10, 2)->nullable()->after('codigo_barras');
            $table->decimal('dimensao_2', 10, 2)->nullable()->after('dimensao_1');
            $table->decimal('dimensao_3', 10, 2)->nullable()->after('dimensao_2');
            $table->string('cor', 150)->nullable()->after('dimensao_3');
            $table->string('lado', 120)->nullable()->after('cor');
            $table->string('material_oficial', 180)->nullable()->after('lado');
            $table->string('acabamento_oficial', 180)->nullable()->after('material_oficial');
            $table->boolean('conflito_codigo')->default(false)->after('acabamento_oficial');
            $table->string('status_revisao', 40)
                ->default(StatusRevisaoCadastro::NAO_REVISADO->value)
                ->after('conflito_codigo');

            $table->index('sku_interno', 'idx_produto_variacoes_sku_interno');
            $table->index('chave_variacao', 'idx_produto_variacoes_chave_variacao');
            $table->index('status_revisao', 'idx_produto_variacoes_status_revisao');
            $table->index('conflito_codigo', 'idx_produto_variacoes_conflito_codigo');
            $table->index(['cor', 'lado'], 'idx_produto_variacoes_cor_lado');
        });
    }

    public function down(): void
    {
        Schema::table('produto_variacoes', function (Blueprint $table) {
            $table->dropIndex('idx_produto_variacoes_sku_interno');
            $table->dropIndex('idx_produto_variacoes_chave_variacao');
            $table->dropIndex('idx_produto_variacoes_status_revisao');
            $table->dropIndex('idx_produto_variacoes_conflito_codigo');
            $table->dropIndex('idx_produto_variacoes_cor_lado');
            $table->dropColumn([
                'sku_interno',
                'chave_variacao',
                'dimensao_1',
                'dimensao_2',
                'dimensao_3',
                'cor',
                'lado',
                'material_oficial',
                'acabamento_oficial',
                'conflito_codigo',
                'status_revisao',
            ]);
        });
    }
};
