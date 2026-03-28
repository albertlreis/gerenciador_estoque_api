<?php

use App\Enums\StatusRevisaoCadastro;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->string('codigo_produto', 120)->nullable()->after('nome');
            $table->string('nome_base_normalizado', 255)->nullable()->after('descricao');
            $table->string('chave_produto', 255)->nullable()->after('nome_base_normalizado');
            $table->string('regra_categoria', 50)->nullable()->after('id_categoria');
            $table->string('status_revisao', 40)
                ->default(StatusRevisaoCadastro::NAO_REVISADO->value)
                ->after('regra_categoria');

            $table->index('codigo_produto', 'idx_produtos_codigo_produto');
            $table->index('chave_produto', 'idx_produtos_chave_produto');
            $table->index('nome_base_normalizado', 'idx_produtos_nome_base_normalizado');
            $table->index('regra_categoria', 'idx_produtos_regra_categoria');
            $table->index('status_revisao', 'idx_produtos_status_revisao');
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropIndex('idx_produtos_codigo_produto');
            $table->dropIndex('idx_produtos_chave_produto');
            $table->dropIndex('idx_produtos_nome_base_normalizado');
            $table->dropIndex('idx_produtos_regra_categoria');
            $table->dropIndex('idx_produtos_status_revisao');
            $table->dropColumn([
                'codigo_produto',
                'nome_base_normalizado',
                'chave_produto',
                'regra_categoria',
                'status_revisao',
            ]);
        });
    }
};
