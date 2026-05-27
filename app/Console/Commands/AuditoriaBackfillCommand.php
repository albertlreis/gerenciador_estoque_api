<?php

namespace App\Console\Commands;

use App\Services\AuditoriaLogBackfillService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AuditoriaBackfillCommand extends Command
{
    protected $signature = 'auditoria:backfill {--cutoff=} {--no-files : Nao importar arquivos de log}';

    protected $description = 'Migra auditorias e logs legados para auditoria_logs de forma idempotente.';

    public function handle(AuditoriaLogBackfillService $backfill): int
    {
        $cutoff = $this->option('cutoff')
            ? Carbon::parse((string) $this->option('cutoff'))
            : now();

        $stats = $backfill->backfill($cutoff, !$this->option('no-files'));

        foreach ($stats as $source => $count) {
            $this->line(sprintf('%s: %d', $source, $count));
        }

        return self::SUCCESS;
    }
}
