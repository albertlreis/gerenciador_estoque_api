<?php

namespace App\Integrations\GoogleCalendar\Exceptions;

class GoogleCalendarException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly string $reason = 'google_calendar_error',
        public readonly array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
