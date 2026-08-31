<?php

namespace App\Exceptions;

use RuntimeException;

class PedidoConversaoRequerReconciliacaoException extends RuntimeException
{
    /** @param array<string,mixed> $detalhes */
    public function __construct(public readonly array $detalhes)
    {
        parent::__construct('A conversão deste pedido exige a reconciliação do fluxo de estoque e entrega.');
    }

    public function render()
    {
        return response()->json([
            'code' => 'CONVERSAO_PEDIDO_REQUER_RECONCILIACAO',
            'message' => $this->getMessage(),
            ...$this->detalhes,
        ], 409);
    }
}
