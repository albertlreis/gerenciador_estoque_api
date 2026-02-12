<?php

namespace Tests\Unit;

use App\Models\OutletFormaPagamento;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoOutlet;
use App\Models\ProdutoVariacaoOutletPagamento;
use App\Services\OutletCatalogoPricingService;
use Tests\TestCase;

class OutletCatalogoPricingServiceTest extends TestCase
{
    public function test_escolhe_melhor_oferta_e_calcula_precos_corretamente(): void
    {
        $service = new OutletCatalogoPricingService();

        $variacaoA = new ProdutoVariacao(['preco' => 120]);
        $variacaoA->setRelation('outlets', collect([
            $this->outletComForma(5, 12, 'PIX', 1),
        ]));

        $variacaoB = new ProdutoVariacao(['preco' => 100]);
        $variacaoB->setRelation('outlets', collect([
            $this->outletComForma(4, 10, 'Cartao', 6),
        ]));

        $resultado = $service->build(collect([$variacaoA, $variacaoB]));

        $this->assertSame(100.0, $resultado['preco_venda']);
        $this->assertSame(10.0, $resultado['percentual_desconto']);
        $this->assertSame(90.0, $resultado['preco_final_venda']);
        $this->assertSame(90.0, $resultado['preco_outlet']);
        $this->assertSame('Cartao (ate 6x)', $resultado['pagamento_label']);
        $this->assertNotEmpty($resultado['pagamento_condicoes']);
    }

    public function test_ignora_outlet_sem_saldo_restante(): void
    {
        $service = new OutletCatalogoPricingService();

        $variacao = new ProdutoVariacao(['preco' => 100]);
        $variacao->setRelation('outlets', collect([
            $this->outletComForma(0, 30, 'PIX', 1),
            $this->outletComForma(3, 10, 'PIX', 1),
        ]));

        $resultado = $service->build(collect([$variacao]));

        $this->assertSame(100.0, $resultado['preco_venda']);
        $this->assertSame(10.0, $resultado['percentual_desconto']);
        $this->assertSame(90.0, $resultado['preco_final_venda']);
    }

    private function outletComForma(int $restante, float $desconto, string $formaNome, int $parcelas): ProdutoVariacaoOutlet
    {
        $formaPagamento = new OutletFormaPagamento([
            'id' => random_int(1000, 9999),
            'nome' => $formaNome,
            'max_parcelas_default' => $parcelas,
        ]);

        $forma = new ProdutoVariacaoOutletPagamento([
            'forma_pagamento_id' => $formaPagamento->id,
            'percentual_desconto' => $desconto,
            'max_parcelas' => $parcelas,
        ]);
        $forma->setRelation('formaPagamento', $formaPagamento);

        $outlet = new ProdutoVariacaoOutlet([
            'quantidade_restante' => $restante,
        ]);
        $outlet->setRelation('formasPagamento', collect([$forma]));

        return $outlet;
    }
}
