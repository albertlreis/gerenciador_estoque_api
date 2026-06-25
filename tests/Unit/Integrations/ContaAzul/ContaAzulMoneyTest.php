<?php

namespace Tests\Unit\Integrations\ContaAzul;

use App\Integrations\ContaAzul\Support\ContaAzulMoney;
use PHPUnit\Framework\TestCase;

class ContaAzulMoneyTest extends TestCase
{
    /**
     * @dataProvider moneyProvider
     */
    public function test_parse_normaliza_formatos_monetarios_da_conta_azul(mixed $input, ?float $expected): void
    {
        $this->assertSame($expected, ContaAzulMoney::parse($input));
    }

    public static function moneyProvider(): array
    {
        return [
            'decimal numerico' => [200000.12, 200000.12],
            'decimal string ponto' => ['200000.12', 200000.12],
            'decimal string virgula' => ['200000,12', 200000.12],
            'pt br milhares' => ['200.000,12', 200000.12],
            'pt br moeda' => ['R$ 200.000,12', 200000.12],
            'pt br inteiro com milhar' => ['200.000', 200000.0],
            'negativo' => ['-R$ 200.000,12', -200000.12],
            'negativo parenteses' => ['(200.000,12)', -200000.12],
            'nulo' => [null, null],
            'invalido' => ['abc', null],
        ];
    }
}
