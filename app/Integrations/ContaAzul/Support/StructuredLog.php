<?php

namespace App\Integrations\ContaAzul\Support;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;

final class StructuredLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function integration(string $message, array $context = [], string $level = 'info'): void
    {
        $app = Facade::getFacadeApplication();
        if ($app === null || !$app->bound('log')) {
            return;
        }

        try {
            Log::log($level, $message, array_merge(['channel' => 'conta_azul'], $context));
        } catch (\Throwable) {
            // Log estruturado nunca deve quebrar o fluxo principal.
        }
    }
}
