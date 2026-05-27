<?php

namespace App\Logging;

use App\Services\AuditoriaLogService;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

class AuditoriaLogMonologHandler extends AbstractProcessingHandler
{
    private static bool $writing = false;

    public function __construct()
    {
        parent::__construct(Logger::DEBUG, true);
    }

    protected function write(array $record): void
    {
        if (self::$writing) {
            return;
        }

        self::$writing = true;

        try {
            app(AuditoriaLogService::class)->registrar([
                'occurred_at' => $record['datetime'] ?? now(),
                'tipo' => 'log',
                'categoria' => 'tecnico',
                'nivel' => $record['level_name'] ?? null,
                'modulo' => $record['channel'] ?? 'app',
                'acao' => 'log',
                'label' => $record['message'] ?? null,
                'message' => $record['message'] ?? null,
                'context_json' => $record['context'] ?? [],
                'source_system' => 'estoque',
                'source_kind' => 'monolog',
                'origem' => 'logger',
                'retention_days' => 90,
            ]);
        } catch (\Throwable) {
            // Logging must never break the main application flow.
        } finally {
            self::$writing = false;
        }
    }
}
