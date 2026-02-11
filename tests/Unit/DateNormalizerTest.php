<?php

namespace Tests\Unit;

use App\Support\Dates\DateNormalizer;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DateNormalizerTest extends TestCase
{
    public function test_normaliza_formatos_comuns(): void
    {
        $this->assertSame('2020-08-14', DateNormalizer::normalizeDate('14/08/2020')->toDateString());
        $this->assertSame('2020-08-14', DateNormalizer::normalizeDate('14/08/20')->toDateString());
        $this->assertSame('2020-08-14', DateNormalizer::normalizeDate('14.08.20')->toDateString());
        $this->assertSame('2020-08-14', DateNormalizer::normalizeDate('14.08.2020')->toDateString());
        $this->assertSame('2020-08-14', DateNormalizer::normalizeDate('2020-08-14')->toDateString());
        $this->assertSame('2020-08-14', DateNormalizer::normalizeDate('2020-08-14T10:20:30Z')->toDateString());
    }

    public function test_normaliza_retorna_null_quando_vazio(): void
    {
        $this->assertNull(DateNormalizer::normalizeDate(null));
        $this->assertNull(DateNormalizer::normalizeDate(''));
    }

    public function test_normaliza_lanca_erro_para_formato_invalido(): void
    {
        $this->expectException(ValidationException::class);
        DateNormalizer::normalizeDate('14-08-2020');
    }
}
