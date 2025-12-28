<?php

namespace App\Services\Movimentacao;

use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Services\DepositoResolver;
use App\Services\EstoqueMovimentacaoService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Movimenta o estoque (saída para cliente/consignação) para cada item.
 */
final class MovimentarEstoqueStrategy implements MovimentacaoStrategy
{
    public function __construct(
        private readonly EstoqueMovimentacaoService $mov,
        private readonly DepositoResolver $resolver
    ) {}

    public function processar(Pedido $pedido, Collection $itensCarrinho, array $depositosMap, int $usuarioId): void
    {
        $loteId = (string) Str::uuid();

        foreach ($itensCarrinho as $cItem) {
            $depId = $this->resolver->resolverParaItem($cItem, $depositosMap);
            if (!$depId) {
                throw ValidationException::withMessages([
                    'depositos_por_item' => ["Selecione o depósito do item {$cItem->id} para registrar a movimentação."]
                ]);
            }

            $pItemId = PedidoItem::query()
                ->where('id_pedido', $pedido->id)
                ->where('id_carrinho_item', $cItem->id)
                ->value('id');

            $this->mov->registrarSaidaPedido(
                variacaoId: (int) $cItem->id_variacao,
                depositoSaidaId: (int) $depId,
                quantidade: (int) $cItem->quantidade,
                usuarioId: (int) $usuarioId,
                observacao: "Pedido #{$pedido->id}",
                pedidoId: (int) $pedido->id,
                pedidoItemId: $pItemId ? (int)$pItemId : null,
                loteId: $loteId,
            );
        }
    }
}
