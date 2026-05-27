<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, string> */
    private array $stagingTables = [
        'stg_conta_azul_pessoas',
        'stg_conta_azul_produtos',
        'stg_conta_azul_vendas',
        'stg_conta_azul_financeiro',
        'stg_conta_azul_baixas',
        'stg_conta_azul_notas',
        'stg_conta_azul_contas_pagar',
        'stg_conta_azul_contas_financeiras',
        'stg_conta_azul_categorias_financeiras',
        'stg_conta_azul_centros_custo',
        'stg_conta_azul_parcelas',
        'stg_conta_azul_saldos_contas_financeiras',
        'stg_conta_azul_formas_pagamento',
    ];

    /** @var array<int, string> */
    private array $legacyTables = [
        'auditoria_mudancas',
        'auditoria_eventos',
        'financeiro_auditorias',
        'activity_log',
        'estoque_logs',
        'assistencia_chamado_logs',
        'produto_variacao_dimensao_auditorias',
        'conta_azul_sync_logs',
        'conta_azul_import_batches',
        'google_calendar_logs',
        'logs_metricas',
    ];

    public function up(): void
    {
        foreach ($this->stagingTables as $table) {
            $this->addCanonicalImportColumns($table);
            $this->backfillCanonicalImportColumns($table);
            $this->dropBatchId($table);
        }

        foreach ($this->legacyTables as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        foreach ($this->stagingTables as $table) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'batch_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('batch_id')->nullable()->index();
            });
        }
    }

    private function addCanonicalImportColumns(string $table): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            if (!Schema::hasColumn($table, 'auditoria_log_id')) {
                $blueprint->unsignedBigInteger('auditoria_log_id')->nullable();
            }
            if (!Schema::hasColumn($table, 'import_run_uid')) {
                $blueprint->char('import_run_uid', 64)->nullable();
            }
        });

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            if (Schema::hasColumn($table, 'auditoria_log_id')) {
                $blueprint->index('auditoria_log_id', $this->indexName($table, 'audit_log'));
            }
            if (Schema::hasColumn($table, 'import_run_uid')) {
                $blueprint->index('import_run_uid', $this->indexName($table, 'run_uid'));
            }
        });
    }

    private function backfillCanonicalImportColumns(string $table): void
    {
        if (
            !Schema::hasTable($table)
            || !Schema::hasColumn($table, 'batch_id')
            || !Schema::hasColumn($table, 'auditoria_log_id')
            || !Schema::hasTable('auditoria_logs')
        ) {
            return;
        }

        DB::table($table)
            ->whereNotNull('batch_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table): void {
                $batchIds = collect($rows)->pluck('batch_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();
                if ($batchIds === []) {
                    return;
                }

                $logs = DB::table('auditoria_logs')
                    ->where('source_table', 'conta_azul_import_batches')
                    ->whereIn('source_id', $batchIds)
                    ->get(['id', 'source_id', 'source_uid'])
                    ->keyBy(fn ($log) => (string) $log->source_id);

                foreach ($rows as $row) {
                    $log = $logs->get((string) $row->batch_id);
                    if (!$log) {
                        continue;
                    }

                    DB::table($table)->where('id', $row->id)->update([
                        'auditoria_log_id' => $log->id,
                        'import_run_uid' => $log->source_uid,
                    ]);
                }
            });
    }

    private function dropBatchId(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'batch_id')) {
            return;
        }

        $this->dropForeignKeysForColumn($table, 'batch_id');

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn('batch_id');
        });
    }

    private function dropForeignKeysForColumn(string $table, string $column): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $constraints = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$table, $column]
            );

            foreach ($constraints as $constraint) {
                Schema::table($table, function (Blueprint $blueprint) use ($constraint): void {
                    $blueprint->dropForeign($constraint->CONSTRAINT_NAME);
                });
            }

            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropForeign([$column]);
            });
        } catch (Throwable) {
            //
        }
    }

    private function indexName(string $table, string $suffix): string
    {
        return 'idx_' . substr(md5($table), 0, 12) . '_' . $suffix;
    }
};
