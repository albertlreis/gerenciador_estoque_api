<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STAGING_TABLES = [
        'stg_conta_azul_baixas' => 'stg_ca_baixas_scope_ext_unq',
        'stg_conta_azul_categorias_financeiras' => 'stg_ca_cat_fin_scope_ext_unq',
        'stg_conta_azul_centros_custo' => 'stg_ca_ccusto_scope_ext_unq',
        'stg_conta_azul_contas_financeiras' => 'stg_ca_contas_fin_scope_ext_unq',
        'stg_conta_azul_contas_pagar' => 'stg_ca_contas_pag_scope_ext_unq',
        'stg_conta_azul_financeiro' => 'stg_ca_fin_scope_ext_unq',
        'stg_conta_azul_formas_pagamento' => 'stg_ca_formas_pag_scope_ext_unq',
        'stg_conta_azul_notas' => 'stg_ca_notas_scope_ext_unq',
        'stg_conta_azul_parcelas' => 'stg_ca_parcelas_scope_ext_unq',
        'stg_conta_azul_pessoas' => 'stg_ca_pessoas_scope_ext_unq',
        'stg_conta_azul_produtos' => 'stg_ca_produtos_scope_ext_unq',
        'stg_conta_azul_saldos_contas_financeiras' => 'stg_ca_saldos_scope_ext_unq',
        'stg_conta_azul_vendas' => 'stg_ca_vendas_scope_ext_unq',
    ];

    private const NON_FINANCIAL_TABLES = [
        'stg_conta_azul_produtos',
        'stg_conta_azul_vendas',
    ];

    public function up(): void
    {
        foreach (self::NON_FINANCIAL_TABLES as $tableName) {
            if (Schema::hasTable($tableName)) {
                DB::table($tableName)->delete();
            }
        }

        foreach (self::STAGING_TABLES as $tableName => $indexName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $this->deduplicate($tableName);

            if (! Schema::hasColumn($tableName, 'loja_scope_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->unsignedBigInteger('loja_scope_id')
                        ->storedAs('COALESCE(`loja_id`, 0)');
                });

                Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                    $table->unique(['loja_scope_id', 'identificador_externo'], $indexName);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::STAGING_TABLES, true) as $tableName => $indexName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'loja_scope_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropUnique($indexName);
                $table->dropColumn('loja_scope_id');
            });
        }
    }

    private function deduplicate(string $tableName): void
    {
        $quotedTable = '`'.str_replace('`', '``', $tableName).'`';

        DB::statement(<<<SQL
            DELETE FROM {$quotedTable}
            WHERE `id` NOT IN (
                SELECT `keep_id`
                FROM (
                    SELECT MAX(`id`) AS `keep_id`
                    FROM {$quotedTable}
                    GROUP BY COALESCE(`loja_id`, 0), `identificador_externo`
                ) AS `staging_survivors`
            )
        SQL);
    }
};
