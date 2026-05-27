<?php

namespace App\Support\Auditoria;

use App\Services\AuditoriaLogService;
use Carbon\Carbon;
use Generator;
use SplFileObject;

class LaravelLogFileParser
{
    /**
     * @return Generator<int,array<string,mixed>>
     */
    public function parse(string $path, string $sourceSystem, string $channel): Generator
    {
        if (!is_file($path)) {
            return;
        }

        $file = new SplFileObject($path, 'r');
        $entry = null;

        while (!$file->eof()) {
            $line = $file->fgets();
            if ($line === '') {
                continue;
            }

            $lineNumber = $file->key() + 1;

            if (preg_match('/^\[(?<datetime>[^\]]+)\]\s+(?<environment>[^.]+)\.(?<level>[A-Z]+):\s(?<message>.*)$/', rtrim($line, "\r\n"), $matches)) {
                if ($entry !== null) {
                    yield $this->finalize($entry, $path, $sourceSystem, $channel);
                }

                $entry = [
                    'line_start' => $lineNumber,
                    'datetime' => $matches['datetime'],
                    'environment' => $matches['environment'],
                    'level' => strtolower($matches['level']),
                    'message' => $matches['message'],
                    'raw' => [$line],
                ];

                continue;
            }

            if ($entry === null) {
                $entry = [
                    'line_start' => $lineNumber,
                    'datetime' => null,
                    'environment' => null,
                    'level' => 'info',
                    'message' => trim($line),
                    'raw' => [$line],
                ];
                continue;
            }

            $entry['raw'][] = $line;
        }

        if ($entry !== null) {
            yield $this->finalize($entry, $path, $sourceSystem, $channel);
        }
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function finalize(array $entry, string $path, string $sourceSystem, string $channel): array
    {
        $relativePath = str_replace('\\', '/', $path);
        $lineStart = (string) ($entry['line_start'] ?? '0');
        $raw = implode('', $entry['raw'] ?? []);
        $message = AuditoriaRedactor::redactString((string) ($entry['message'] ?? ''));

        return [
            'occurred_at' => $this->parseDate($entry['datetime'] ?? null),
            'tipo' => 'log',
            'categoria' => 'tecnico',
            'nivel' => $entry['level'] ?? 'info',
            'modulo' => $channel,
            'acao' => 'log_file',
            'label' => substr($message, 0, 255),
            'message' => $message,
            'context_json' => [
                'environment' => $entry['environment'] ?? null,
                'file' => basename($path),
                'line_start' => (int) $lineStart,
            ],
            'raw_excerpt' => $raw,
            'source_system' => $sourceSystem,
            'source_kind' => 'log_file',
            'source_table' => $relativePath,
            'source_id' => $lineStart,
            'source_uid' => AuditoriaLogService::sourceUid($sourceSystem, 'log_file', $relativePath, $lineStart),
            'retention_days' => 90,
        ];
    }

    private function parseDate(mixed $value): Carbon
    {
        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return now();
    }
}
