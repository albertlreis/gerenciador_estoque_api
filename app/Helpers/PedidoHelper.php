<?php

namespace App\Helpers;

use Carbon\Carbon;
use App\Enums\PedidoStatus;

class PedidoHelper
{
    public static function previsoes(array $datas, array $prazos): array
    {
        return [
            PedidoStatus::ENVIADO_FABRICA->value =>
                isset($datas['pedido_criado']) ? Carbon::parse($datas['pedido_criado'])->addDays($prazos['envio_fabrica'] ?? 5) : null,

            PedidoStatus::PREVISAO_ENTREGA_ESTOQUE->value =>
                isset($datas['embarque_fabrica']) ? Carbon::parse($datas['embarque_fabrica'])->addDays($prazos['entrega_estoque'] ?? 7) : null,

            PedidoStatus::PREVISAO_ENVIO_CLIENTE->value =>
                isset($datas['entrega_estoque']) ? Carbon::parse($datas['entrega_estoque'])->addDays($prazos['envio_cliente'] ?? 3) : null,

            PedidoStatus::DEVOLUCAO_CONSIGNACAO->value =>
                isset($datas['consignado']) ? Carbon::parse($datas['consignado'])->addDays($prazos['prazo_consignacao'] ?? 15) : null,
        ];
    }
}
