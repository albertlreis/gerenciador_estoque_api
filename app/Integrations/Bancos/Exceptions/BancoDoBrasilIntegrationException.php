<?php

namespace App\Integrations\Bancos\Exceptions;

use RuntimeException;
use Throwable;

class BancoDoBrasilIntegrationException extends RuntimeException
{
    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        string $message,
        public readonly string $reason = 'bb_extratos_error',
        public readonly array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
