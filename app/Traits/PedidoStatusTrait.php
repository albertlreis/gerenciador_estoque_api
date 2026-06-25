<?php

namespace App\Traits;

use App\Enums\PedidoStatus;
use App\Models\Pedido;
use App\Services\PedidoStatusFluxoService;
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
        return PedidoStatus::tryFrom((string) $this->getStatusAtualCodigo($pedido));
    }

    protected function getStatusAtualCodigo(Pedido $pedido): ?string
    {
        $statusAtual = $pedido->statusAtual;

        if (!$statusAtual) {
            return null;
        }

        return app(PedidoStatusFluxoService::class)->normalizarStatus(
            $statusAtual->getRawOriginal('status') ?: $statusAtual->status
        );
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
     * @return array<string, mixed>|null
     */
    protected function getProximoStatus(Pedido $pedido): ?array
    {
        return app(PedidoStatusFluxoService::class)->proximoStatusDetalhado($pedido);
    }

    /**
     * Retorna a data prevista para o próximo status.
     *
     * @param Pedido $pedido
     * @return Carbon|null
     */
    protected function getPrevisaoProximoStatus(Pedido $pedido): ?Carbon
    {
        return app(PedidoStatusFluxoService::class)->previsaoProximoStatus($pedido);
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
        return $previsao && Carbon::now(config('app.timezone', 'America/Belem'))->greaterThan($previsao);
    }

    /**
     * Informa se o status atual do pedido ainda deve contar para o prazo de entrega ao cliente.
     */
    protected function contaPrazoEntrega(mixed $status): bool
    {
        $status = app(PedidoStatusFluxoService::class)->normalizarStatus($status);

        if (!$status) {
            return false;
        }

        return in_array($status, [
            PedidoStatus::PEDIDO_CRIADO->value,
            PedidoStatus::ENVIADO_FABRICA->value,
            PedidoStatus::NOTA_EMITIDA->value,
            PedidoStatus::PREVISAO_EMBARQUE_FABRICA->value,
            PedidoStatus::EMBARQUE_FABRICA->value,
            PedidoStatus::NOTA_RECEBIDA_COMPRA->value,
            PedidoStatus::PREVISAO_ENTREGA_ESTOQUE->value,
            PedidoStatus::ENTREGA_ESTOQUE->value,
            PedidoStatus::PREVISAO_ENVIO_CLIENTE->value,
            PedidoStatus::ENVIO_CLIENTE->value,
        ], true);
    }
}
