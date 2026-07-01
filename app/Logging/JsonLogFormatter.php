<?php

namespace App\Logging;

use App\Support\Auditoria\AuditoriaRedactor;
use App\Support\Logging\SierraLog;
use Monolog\Formatter\JsonFormatter;

class JsonLogFormatter extends JsonFormatter
{
    public function format(array $record): string
    {
        $eventName = SierraLog::normalizeEventName((string) (($record['context']['event_name'] ?? null) ?: ($record['message'] ?? '')));
        $eventDomain = (string) (($record['context']['event_domain'] ?? null) ?: strtok($eventName, '.'));

        $record['message'] = AuditoriaRedactor::redactString((string) ($record['message'] ?? ''));
        $record['event_name'] = $eventName;
        $record['event_domain'] = $eventDomain ?: 'system';
        $record['context'] = AuditoriaRedactor::redact(array_merge([
            'event_name' => $eventName,
            'event_domain' => $eventDomain ?: 'system',
            'app' => config('app.name'),
            'env' => config('app.env'),
            'service' => env('APP_SERVICE', 'gerenciador-estoque-api'),
        ], $record['context'] ?? []));
        $record['extra'] = AuditoriaRedactor::redact($record['extra'] ?? []);
        $record['app'] = [
            'name' => config('app.name'),
            'env' => config('app.env'),
            'service' => env('APP_SERVICE', 'gerenciador-estoque-api'),
        ];

        return parent::format($record);
    }
}
