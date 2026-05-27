<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AuditoriaLegacyBackupCommand extends Command
{
    protected $signature = 'auditoria:legacy-backup {--delete-logs : Remove arquivos .log depois de arquivar}';

    protected $description = 'Exporta tabelas e arquivos legados de auditoria/logs antes da remocao.';

    /** @var array<int, string> */
    private array $tables = [
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

    public function handle(): int
    {
        $timestamp = now()->format('Ymd_His');
        $base = storage_path("app/backups/auditoria-legacy/{$timestamp}");
        $tablesDir = "{$base}/tables";
        $logsDir = "{$base}/logs";

        File::ensureDirectoryExists($tablesDir);
        File::ensureDirectoryExists($logsDir);

        $manifest = [
            'created_at' => now()->toISOString(),
            'database' => DB::getDatabaseName(),
            'tables' => [],
            'logs' => [],
        ];

        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                $manifest['tables'][$table] = ['exists' => false, 'rows' => 0];
                continue;
            }

            $path = "{$tablesDir}/{$table}.jsonl";
            $handle = fopen($path, 'wb');
            $rows = 0;

            DB::table($table)->orderBy('id')->chunkById(500, function ($items) use ($handle, &$rows): void {
                foreach ($items as $item) {
                    fwrite($handle, json_encode((array) $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
                    $rows++;
                }
            });

            fclose($handle);
            $manifest['tables'][$table] = [
                'exists' => true,
                'rows' => $rows,
                'file' => "tables/{$table}.jsonl",
                'sha256' => hash_file('sha256', $path),
            ];
        }

        foreach ($this->logSources() as $source => $dir) {
            $targetDir = "{$logsDir}/{$source}";
            File::ensureDirectoryExists($targetDir);

            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.log') ?: [] as $file) {
                $target = $targetDir . DIRECTORY_SEPARATOR . basename($file);
                File::copy($file, $target);
                $manifest['logs'][] = [
                    'source' => $source,
                    'file' => "logs/{$source}/" . basename($file),
                    'bytes' => filesize($target) ?: 0,
                    'sha256' => hash_file('sha256', $target),
                ];

                if ($this->option('delete-logs')) {
                    @unlink($file);
                }
            }
        }

        File::put(
            "{$base}/manifest.json",
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->info("Backup legado criado em {$base}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function logSources(): array
    {
        return [
            'estoque' => storage_path('logs'),
            'auth' => base_path('..' . DIRECTORY_SEPARATOR . 'autenticacao_api' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs'),
        ];
    }
}
