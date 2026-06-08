<?php

namespace Tests\Unit;

use App\Http\Controllers\Integrations\GoogleCalendarController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class GoogleCalendarControllerValidationTest extends TestCase
{
    public function test_update_requires_start_and_end_together(): void
    {
        $method = (new ReflectionClass(GoogleCalendarController::class))->getMethod('eventValidationRules');
        $method->setAccessible(true);

        $rules = $method->invoke(null, false);

        $this->assertContains('required_with:end', $rules['start']);
        $this->assertContains('required_with:start', $rules['end']);
    }

    public function test_create_keeps_start_and_end_required(): void
    {
        $method = (new ReflectionClass(GoogleCalendarController::class))->getMethod('eventValidationRules');
        $method->setAccessible(true);

        $rules = $method->invoke(null, true);

        $this->assertContains('required', $rules['start']);
        $this->assertContains('required', $rules['end']);
        $this->assertNotContains('required_with:end', $rules['start']);
        $this->assertNotContains('required_with:start', $rules['end']);
    }
}
