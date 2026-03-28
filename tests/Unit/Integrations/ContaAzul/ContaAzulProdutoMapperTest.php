<?php

namespace Tests\Unit\Integrations\ContaAzul;

use App\Integrations\ContaAzul\Mappers\ContaAzulProdutoMapper;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use PHPUnit\Framework\TestCase;

class ContaAzulProdutoMapperTest extends TestCase
{
    public function test_from_local_prefers_variacao_sku(): void
    {
        $mapper = new ContaAzulProdutoMapper();
        $produto = new Produto([
            'nome' => 'Produto A',
            'codigo_produto' => 'P-1',
        ]);
        $variacao = new ProdutoVariacao([
            'sku_interno' => 'SKU-V',
            'referencia' => 'REF-9',
        ]);

        $payload = $mapper->fromLocal($produto, $variacao);

        $this->assertSame('Produto A', $payload['nome']);
        $this->assertSame('P-1', $payload['codigo']);
        $this->assertSame('SKU-V', $payload['sku']);
        $this->assertSame('REF-9', $payload['referencia']);
    }
}
