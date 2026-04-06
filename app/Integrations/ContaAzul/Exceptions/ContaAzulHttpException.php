<?php

namespace App\Integrations\ContaAzul\Exceptions;

class ContaAzulHttpException extends ContaAzulException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $responseBody = null,
        string $reason = 'http_error',
        array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $reason, $context, $previous);
    }
}
