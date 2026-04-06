<?php

namespace Tests\Unit\Integrations\ContaAzul;

use App\Integrations\ContaAzul\Mappers\ContaAzulTituloMapper;
use App\Models\ContaReceber;
use Tests\TestCase;

class ContaAzulTituloMapperTest extends TestCase
{
    public function test_from_local_uses_valor_liquido_do_modelo_sierra(): void
    {
        $mapper = new ContaAzulTituloMapper();
        $conta = new ContaReceber([
            'descricao' => 'Parcela 1',
            'numero_documento' => 'CR-1',
            'valor_bruto' => '100.00',
            'desconto' => '10.00',
            'juros' => '0.00',
            'multa' => '0.00',
            'data_emissao' => '2025-01-01',
            'data_vencimento' => '2025-02-01',
        ]);

        $payload = $mapper->fromLocal($conta, null);

        $this->assertSame('Parcela 1', $payload['descricao']);
        $this->assertSame('CR-1', $payload['numero_documento']);
        $this->assertEqualsWithDelta(90.0, $payload['valor'], 0.001);
    }
}
