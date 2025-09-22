<?php

namespace App\Services;

/**
 * Resolve o depósito a ser usado por item do carrinho,
 * aplicando a regra: mapa enviado pela UI > item.id_deposito > null.
 */
final class DepositoResolver
{
    /**
     * @param  object $itemCarrinho Objeto/Model CarrinhoItem
     * @param  array  $depositosMap ['id_carrinho_item' => 'id_deposito']
     * @return int|null
     */
    public function resolverParaItem(object $itemCarrinho, array $depositosMap): ?int
    {
        return $depositosMap[$itemCarrinho->id] ?? $itemCarrinho->id_deposito ?? null;
    }
}
