<?php

namespace App\Integrations\ContaAzul\Mappers;

use App\Models\Produto;
use App\Models\ProdutoVariacao;

class ContaAzulProdutoMapper
{
    /**
     * Exporta o catálogo Sierra: {@see Produto} é a entidade canônica; {@see ProdutoVariacao} define SKU/referência quando existir.
     * O vínculo externo em {@see ContaAzulMapeamento} usa sempre id_local = produto.id.
     */
    public function fromLocal(Produto $produto, ?ProdutoVariacao $variacao = null): array
    {
        $sku = $variacao?->sku_interno ?? $produto->codigo_produto;

        return array_filter([
            'nome' => $produto->nome,
            'codigo' => $produto->codigo_produto,
            'sku' => $sku,
            'referencia' => $variacao?->referencia,
            'tipo' => 'PRODUTO',
        ], fn ($v) => $v !== null && $v !== '');
    }
}
