<?php

namespace App\Integrations\ContaAzul\Exceptions;

use RuntimeException;

class ContaAzulException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly string $reason = 'conta_azul_erro',
        public readonly array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
