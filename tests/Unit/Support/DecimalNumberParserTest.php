<?php

namespace Tests\Unit\Support;

use App\Support\Numbers\DecimalNumberParser;
use PHPUnit\Framework\TestCase;

class DecimalNumberParserTest extends TestCase
{
    /**
     * @dataProvider decimalProvider
     */
    public function test_converte_formatos_decimais_us_e_br(string $input, float $expected, string $normalized): void
    {
        $this->assertSame($expected, DecimalNumberParser::toFloat($input));
        $this->assertSame($normalized, DecimalNumberParser::normalize($input));
    }

    public static function decimalProvider(): array
    {
        return [
            'decimal com ponto' => ['1234.56', 1234.56, '1234.56'],
            'decimal com virgula' => ['1234,56', 1234.56, '1234.56'],
            'br com milhar' => ['1.234,56', 1234.56, '1234.56'],
            'us com milhar' => ['1,234.56', 1234.56, '1234.56'],
        ];
    }

    public function test_retorna_fallback_para_valor_vazio_ou_invalido(): void
    {
        $this->assertSame(7.5, DecimalNumberParser::toFloat('', 7.5));
        $this->assertSame('7.5', DecimalNumberParser::normalize('', 7.5));
        $this->assertSame(7.5, DecimalNumberParser::toFloat('abc', 7.5));
        $this->assertSame('7.5', DecimalNumberParser::normalize('abc', 7.5));
    }
}
