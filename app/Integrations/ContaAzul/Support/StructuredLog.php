<?php

namespace App\Integrations\ContaAzul\Support;

use App\Support\Logging\SierraLog;

final class StructuredLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function integration(string $message, array $context = [], string $level = 'info'): void
    {
        SierraLog::integration($message, array_merge(['channel' => 'conta_azul'], $context), $level);
    }
}
