<?php

namespace App\Helpers;

use App\Models\Pedido;
use App\Enums\PedidoStatus;
use Illuminate\Support\Carbon;

class PedidoHelper
{
    public static function previsoes(array $datas, array $prazos): array
    {
        return [
            PedidoStatus::ENVIADO_FABRICA->value =>
                isset($datas[PedidoStatus::PEDIDO_CRIADO->value])
                    ? Carbon::parse($datas[PedidoStatus::PEDIDO_CRIADO->value])->addDays($prazos['prazo_envio_fabrica'] ?? 5)
                    : null,

            PedidoStatus::PREVISAO_EMBARQUE_FABRICA->value =>
                isset($datas[PedidoStatus::NOTA_EMITIDA->value])
                    ? Carbon::parse($datas[PedidoStatus::NOTA_EMITIDA->value])->addDays($prazos['dias_previsao_embarque_fabrica'] ?? 7)
                    : null,

            PedidoStatus::PREVISAO_ENTREGA_ESTOQUE->value =>
                isset($datas[PedidoStatus::EMBARQUE_FABRICA->value])
                    ? Carbon::parse($datas[PedidoStatus::EMBARQUE_FABRICA->value])->addDays($prazos['prazo_entrega_estoque'] ?? 7)
                    : null,

            PedidoStatus::PREVISAO_ENVIO_CLIENTE->value =>
                isset($datas[PedidoStatus::ENTREGA_ESTOQUE->value])
                    ? Carbon::parse($datas[PedidoStatus::ENTREGA_ESTOQUE->value])->addDays($prazos['prazo_envio_cliente'] ?? 3)
                    : null,

            PedidoStatus::ENTREGA_CLIENTE->value =>
                isset($datas[PedidoStatus::ENVIO_CLIENTE->value])
                    ? Carbon::parse($datas[PedidoStatus::ENVIO_CLIENTE->value])->addDays($prazos['dias_previsao_entrega_cliente'] ?? 3)
                    : null,

            PedidoStatus::DEVOLUCAO_CONSIGNACAO->value =>
                isset($datas[PedidoStatus::CONSIGNADO->value])
                    ? Carbon::parse($datas[PedidoStatus::CONSIGNADO->value])->addDays($prazos['prazo_consignacao'] ?? 15)
                    : null,
        ];
    }

    public static function fluxoPorTipo(Pedido $pedido): array
    {
        if ($pedido->consignado) {
            return [
                PedidoStatus::PEDIDO_CRIADO,
                PedidoStatus::CONSIGNADO,
                PedidoStatus::DEVOLUCAO_CONSIGNACAO,
                PedidoStatus::FINALIZADO
            ];
        }

        if ($pedido->via_estoque) {
            return [
                PedidoStatus::PEDIDO_CRIADO,
                PedidoStatus::ENTREGA_ESTOQUE,
                PedidoStatus::ENVIO_CLIENTE,
                PedidoStatus::ENTREGA_CLIENTE,
                PedidoStatus::FINALIZADO
            ];
        }

        return [
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
            PedidoStatus::ENTREGA_CLIENTE,
            PedidoStatus::FINALIZADO
        ];
    }

    public static function fluxo(): array
    {
        return array_map(
            fn ($status) => $status->value,
            [
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
                PedidoStatus::ENTREGA_CLIENTE,
                PedidoStatus::FINALIZADO
            ]
        );
    }

}
