<?php

use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\PedidoStatus;

return [
    'cache' => [
        'ttl_seconds' => (int) env('DASHBOARD_CACHE_TTL', 300),
    ],

    'consignacoes' => [
        'dias_vencendo' => (int) env('DASHBOARD_CONSIGNACOES_DIAS', 2),
    ],

    'periods' => [
        'allowed' => ['today', '7d', 'month', '6m', 'custom'],
        'default' => 'month',
    ],

    'status_groups' => [
        'criado' => [
            PedidoStatus::PEDIDO_CRIADO->value,
        ],
        'fabrica' => [
            PedidoStatus::ENVIADO_FABRICA->value,
            PedidoStatus::NOTA_EMITIDA->value,
            PedidoStatus::PREVISAO_EMBARQUE_FABRICA->value,
            PedidoStatus::EMBARQUE_FABRICA->value,
        ],
        'recebimento' => [
            PedidoStatus::NOTA_RECEBIDA_COMPRA->value,
            PedidoStatus::PREVISAO_ENTREGA_ESTOQUE->value,
            PedidoStatus::ENTREGA_ESTOQUE->value,
        ],
        'envio_cliente' => [
            PedidoStatus::PREVISAO_ENVIO_CLIENTE->value,
            PedidoStatus::ENVIO_CLIENTE->value,
            PedidoStatus::ENTREGA_CLIENTE->value,
        ],
        'consignacao' => [
            PedidoStatus::CONSIGNADO->value,
            PedidoStatus::DEVOLUCAO_CONSIGNACAO->value,
        ],
        'finalizado' => [
            PedidoStatus::FINALIZADO->value,
        ],
    ],

    'estoque' => [
        'tipos_entrada' => [
            EstoqueMovimentacaoTipo::ENTRADA->value,
            EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
            EstoqueMovimentacaoTipo::CONSIGNACAO_DEVOLUCAO->value,
            EstoqueMovimentacaoTipo::ASSISTENCIA_RETORNO->value,
        ],
        'tipos_saida' => [
            EstoqueMovimentacaoTipo::SAIDA->value,
            EstoqueMovimentacaoTipo::SAIDA_ENTREGA_CLIENTE->value,
            EstoqueMovimentacaoTipo::CONSIGNACAO_ENVIO->value,
            EstoqueMovimentacaoTipo::ASSISTENCIA_ENVIO->value,
        ],
        'tipos_transferencia' => [
            EstoqueMovimentacaoTipo::TRANSFERENCIA->value,
        ],
    ],

    'permissions' => [
        'seller_view_all' => 'pedidos.visualizar.todos',
    ],
];
