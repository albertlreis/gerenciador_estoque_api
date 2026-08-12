<?php

namespace App\Console\Commands;

use App\Models\AuditoriaLog;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class GoogleCalendarSanitizeAuditLogsCommand extends Command
{
    protected $signature = 'google-calendar:sanitize-audit-logs
        {--apply : Aplicar a sanitizacao; sem esta opcao apenas conta os registros}';

    protected $description = 'Remove conteudo de eventos dos logs historicos do Google Agenda.';

    public function handle(): int
    {
        if (!Schema::hasTable('auditoria_logs')) {
            $this->warn('Tabela auditoria_logs nao existe.');
            return self::SUCCESS;
        }

        $query = AuditoriaLog::query()->where('modulo', 'google_calendar');
        $count = (clone $query)->count();

        if (!$this->option('apply')) {
            $this->line("Registros que serao sanitizados: {$count}");
            return self::SUCCESS;
        }

        $query->orderBy('id')->chunkById(200, function ($logs): void {
            foreach ($logs as $log) {
                $context = array_filter(
                    Arr::only($log->context_json ?? [], [
                        'conexao_id',
                        'calendar_id',
                        'event_id',
                        'erro_codigo',
                    ]),
                    static fn ($value) => $value !== null && $value !== ''
                );

                $log->forceFill([
                    'message' => $log->status === 'erro'
                        ? 'Falha na operacao solicitada ao Google Agenda.'
                        : 'Operacao solicitada ao Google Agenda concluida.',
                    'context_json' => $context === [] ? null : $context,
                    'metadata_json' => null,
                    'raw_excerpt' => null,
                    'retention_days' => 365,
                ])->save();
            }
        });

        $this->line("Registros sanitizados: {$count}");

        return self::SUCCESS;
    }
}
