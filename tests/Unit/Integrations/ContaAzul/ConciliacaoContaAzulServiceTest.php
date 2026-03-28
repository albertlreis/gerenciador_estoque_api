<?php

namespace Tests\Unit\Integrations\ContaAzul;

use App\Integrations\ContaAzul\Services\ConciliacaoContaAzulService;
use PHPUnit\Framework\TestCase;

class ConciliacaoContaAzulServiceTest extends TestCase
{
    public function test_normalize_documento_strips_non_digits(): void
    {
        $svc = new ConciliacaoContaAzulService();
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('normalizeDocumento');
        $m->setAccessible(true);

        $this->assertSame('12345678901', $m->invoke($svc, '123.456.789-01'));
    }
}
