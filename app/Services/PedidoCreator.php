<?php

namespace App\Services;

use App\Http\Requests\StorePedidoRequest;
use Illuminate\Http\JsonResponse;

/**
 * Compat (fachada) para o fluxo de finalização de pedido.
 *
 * Mantido para não quebrar chamadas existentes ao antigo "PedidoCreator".
 * Internamente delega para o novo orquestrador FinalizarPedidoService.
 */
class PedidoCreator
{
    public function __construct(
        private readonly FinalizarPedidoService $finalizador
    ) {}

    /**
     * Cria um pedido a partir do carrinho informado na request,
     * incluindo lógica de consignação, movimentação e/ou reserva.
     *
     * @param StorePedidoRequest $request
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function criar(StorePedidoRequest $request): JsonResponse
    {
        return $this->finalizador->executar($request);
    }
}
