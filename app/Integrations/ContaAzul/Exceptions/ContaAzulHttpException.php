<?php

namespace App\Integrations\ContaAzul\Exceptions;

class ContaAzulHttpException extends ContaAzulException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $responseBody = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
