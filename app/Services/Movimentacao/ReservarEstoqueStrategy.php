<?php

namespace App\Services\Movimentacao;

use App\Models\Pedido;
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
        foreach ($itensCarrinho as $item) {
            $depId = $this->resolver->resolverParaItem($item, $depositosMap);

            if ($depId) {
                $this->reservas->reservar(
                    variacaoId: $item->id_variacao,
                    depositoId: (int) $depId,
                    quantidade: (int) $item->quantidade,
                    pedidoId: $pedido->id,
                    motivo: 'pedido_sem_movimentacao'
                );
            }
        }
    }
}
