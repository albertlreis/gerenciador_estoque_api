<?php

namespace App\Services\Movimentacao;

use App\Models\Pedido;
use App\Services\DepositoResolver;
use App\Services\EstoqueMovimentacaoService;
use Illuminate\Support\Collection;
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
        foreach ($itensCarrinho as $item) {
            $depId = $this->resolver->resolverParaItem($item, $depositosMap);
            if (!$depId) {
                throw ValidationException::withMessages([
                    'depositos_por_item' => ["Selecione o depósito do item {$item->id} para registrar a movimentação."]
                ]);
            }

            $this->mov->registrarSaidaEntregaCliente(
                variacaoId: $item->id_variacao,
                depositoSaidaId: (int) $depId,
                quantidade: (int) $item->quantidade,
                usuarioId: $usuarioId,
                observacao: "Pedido #{$pedido->id}"
            );
        }
    }
}
