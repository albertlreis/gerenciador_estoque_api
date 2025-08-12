<?php

namespace App\Traits;

use App\Enums\PedidoStatus;
use App\Helpers\PedidoHelper;
use App\Models\Pedido;
use Illuminate\Support\Carbon;

/**
 * Trait para cálculo de status, previsão e atraso de pedidos.
 */
trait PedidoStatusTrait
{
    /**
     * Retorna o status atual do pedido.
     *
     * @param Pedido $pedido
     * @return \App\Enums\PedidoStatus|null
     */
    protected function getStatusAtualEnum(Pedido $pedido): ?PedidoStatus
    {
        return optional($pedido->statusAtual)->status;
    }

    /**
     * Retorna a data do último status.
     *
     * @param Pedido $pedido
     * @return string|null
     */
    protected function getDataUltimoStatus(Pedido $pedido): ?string
    {
        return optional($pedido->statusAtual)->data_status;
    }

    /**
     * Retorna o próximo status esperado no fluxo.
     *
     * @param Pedido $pedido
     * @return \App\Enums\PedidoStatus|null
     */
    protected function getProximoStatus(Pedido $pedido): ?PedidoStatus
    {
        return PedidoHelper::proximoStatusEsperado($pedido);
    }

    /**
     * Retorna a data prevista para o próximo status.
     *
     * @param Pedido $pedido
     * @return Carbon|null
     */
    protected function getPrevisaoProximoStatus(Pedido $pedido): ?Carbon
    {
        return PedidoHelper::previsaoProximoStatus($pedido);
    }

    /**
     * Verifica se o pedido está atrasado com base na previsão.
     *
     * @param Pedido $pedido
     * @return bool
     */
    protected function isAtrasado(Pedido $pedido): bool
    {
        $previsao = $this->getPrevisaoProximoStatus($pedido);
        return $previsao && Carbon::now()->greaterThan($previsao);
    }

    /**
     * Informa se o status atual do pedido ainda deve contar para o prazo de entrega ao cliente.
     */
    protected function contaPrazoEntrega(?PedidoStatus $status): bool
    {
        if (!$status) return false;

        return in_array($status, [
            PedidoStatus::PEDIDO_CRIADO,
            PedidoStatus::ENVIADO_FABRICA,
            PedidoStatus::NOTA_EMITIDA,
            PedidoStatus::PREVISAO_EMBARQUE_FABRICA,
            PedidoStatus::EMBARQUE_FABRICA,
            PedidoStatus::NOTA_RECEBIDA_COMPRA,
            PedidoStatus::PREVISAO_ENTREGA_ESTOQUE,
            PedidoStatus::ENTREGA_ESTOQUE,
            PedidoStatus::PREVISAO_ENVIO_CLIENTE,
            PedidoStatus::ENVIO_CLIENTE,
        ], true);
    }
}
