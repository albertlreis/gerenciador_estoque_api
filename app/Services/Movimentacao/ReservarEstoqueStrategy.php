<?php

namespace App\Services\Movimentacao;

use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Services\DepositoResolver;
use App\Services\ReservaEstoqueService;
use Illuminate\Support\Collection;

/**
 * Cria reservas de estoque (quando o usuário opta por não movimentar agora).
 */
final class ReservarEstoqueStrategy implements MovimentacaoStrategy
{
    public function __construct(
        private readonly ReservaEstoqueService $reservas,
        private readonly DepositoResolver $resolver
    ) {}

    public function processar(Pedido $pedido, Collection $itensCarrinho, array $depositosMap, int $usuarioId): void
    {
        foreach ($itensCarrinho as $cItem) {
            $depId = $this->resolver->resolverParaItem($cItem, $depositosMap);
            if (!$depId) continue;

            $pItemId = PedidoItem::query()
                ->where('id_pedido', $pedido->id)
                ->where('id_carrinho_item', $cItem->id)
                ->value('id');

            $this->reservas->reservar(
                variacaoId: (int) $cItem->id_variacao,
                depositoId: $depId,
                quantidade: (int) $cItem->quantidade,
                pedidoId: (int) $pedido->id,
                pedidoItemId: $pItemId ? (int) $pItemId : null,
                usuarioId: $usuarioId,
                motivo: 'pedido_sem_movimentacao'
            );
        }
    }

}
