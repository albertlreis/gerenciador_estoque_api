<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('produtos')) {
            return;
        }

        Schema::table('produtos', function (Blueprint $table) {
            foreach ([
                'idx_produtos_chave_produto',
                'idx_produtos_nome_base_normalizado',
                'idx_produtos_regra_categoria',
                'idx_produtos_status_revisao',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable) {
                    // Índice pode não existir em ambientes legados.
                }
            }
        });

        $columns = array_values(array_filter([
            Schema::hasColumn('produtos', 'nome_base_normalizado') ? 'nome_base_normalizado' : null,
            Schema::hasColumn('produtos', 'chave_produto') ? 'chave_produto' : null,
            Schema::hasColumn('produtos', 'regra_categoria') ? 'regra_categoria' : null,
            Schema::hasColumn('produtos', 'status_revisao') ? 'status_revisao' : null,
        ]));

        if (!empty($columns)) {
            Schema::table('produtos', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('produtos')) {
            return;
        }

        Schema::table('produtos', function (Blueprint $table) {
            if (!Schema::hasColumn('produtos', 'nome_base_normalizado')) {
                $table->string('nome_base_normalizado', 255)->nullable()->after('descricao');
            }
            if (!Schema::hasColumn('produtos', 'chave_produto')) {
                $table->string('chave_produto', 255)->nullable()->after('nome_base_normalizado');
            }
            if (!Schema::hasColumn('produtos', 'regra_categoria')) {
                $table->string('regra_categoria', 50)->nullable()->after('id_categoria');
            }
            if (!Schema::hasColumn('produtos', 'status_revisao')) {
                $table->string('status_revisao', 40)->default('nao_revisado')->after('regra_categoria');
            }
        });

        Schema::table('produtos', function (Blueprint $table) {
            try {
                $table->index('chave_produto', 'idx_produtos_chave_produto');
            } catch (\Throwable) {
            }

            try {
                $table->index('nome_base_normalizado', 'idx_produtos_nome_base_normalizado');
            } catch (\Throwable) {
            }

            try {
                $table->index('regra_categoria', 'idx_produtos_regra_categoria');
            } catch (\Throwable) {
            }

            try {
                $table->index('status_revisao', 'idx_produtos_status_revisao');
            } catch (\Throwable) {
            }
        });
    }
};
