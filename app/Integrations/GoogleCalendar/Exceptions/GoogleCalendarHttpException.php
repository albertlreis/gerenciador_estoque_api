<?php

namespace App\Integrations\GoogleCalendar\Exceptions;

class GoogleCalendarHttpException extends GoogleCalendarException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly mixed $response = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 'http_error', ['status' => $status, 'response' => $response], $previous);
    }
}
