<?php

namespace App\Logging;

use Monolog\Logger as MonologLogger;

class AuditoriaLogTap
{
    public function __invoke($logger): void
    {
        $monolog = method_exists($logger, 'getLogger') ? $logger->getLogger() : $logger;

        if ($monolog instanceof MonologLogger) {
            $monolog->pushHandler(new AuditoriaLogMonologHandler());
        }
    }
}
