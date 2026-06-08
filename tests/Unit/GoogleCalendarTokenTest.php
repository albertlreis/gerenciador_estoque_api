<?php

namespace Tests\Unit;

use App\Integrations\GoogleCalendar\Models\GoogleCalendarToken;
use PHPUnit\Framework\TestCase;

class GoogleCalendarTokenTest extends TestCase
{
    public function test_tokens_are_encrypted_and_hidden(): void
    {
        $token = new GoogleCalendarToken();

        $this->assertSame('encrypted', $token->getCasts()['access_token'] ?? null);
        $this->assertSame('encrypted', $token->getCasts()['refresh_token'] ?? null);
        $this->assertContains('access_token', $token->getHidden());
        $this->assertContains('refresh_token', $token->getHidden());
    }
}
