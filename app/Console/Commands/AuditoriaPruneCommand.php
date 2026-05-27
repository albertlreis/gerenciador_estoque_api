<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditoriaPruneCommand extends Command
{
    protected $signature = 'auditoria:prune {--dry-run : Apenas contar registros elegiveis}';

    protected $description = 'Remove auditorias/logs unificados vencidos pela politica de retencao.';

    public function handle(): int
    {
        if (!Schema::hasTable('auditoria_logs')) {
            $this->warn('Tabela auditoria_logs nao existe.');
            return self::SUCCESS;
        }

        $query = DB::table('auditoria_logs')
            ->whereRaw('occurred_at < DATE_SUB(NOW(), INTERVAL retention_days DAY)');

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->line("Registros elegiveis: {$count}");
            return self::SUCCESS;
        }

        $query->orderBy('id')->chunkById(500, function ($rows): void {
            DB::table('auditoria_logs')
                ->whereIn('id', collect($rows)->pluck('id')->all())
                ->delete();
        });

        $this->line("Registros removidos: {$count}");

        return self::SUCCESS;
    }
}
