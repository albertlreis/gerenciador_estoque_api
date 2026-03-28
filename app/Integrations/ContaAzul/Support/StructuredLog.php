<?php

namespace App\Integrations\ContaAzul\Support;

use Illuminate\Support\Facades\Log;

final class StructuredLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function integration(string $message, array $context = [], string $level = 'info'): void
    {
        Log::log($level, $message, array_merge(['channel' => 'conta_azul'], $context));
    }
}
