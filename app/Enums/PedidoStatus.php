<?php

namespace App\Enums;

enum PedidoStatus: string
{
    case PEDIDO_CRIADO = 'pedido_criado';
    case ENVIADO_FABRICA = 'pedido_enviado_fabrica';
    case NOTA_EMITIDA = 'nota_emitida';
    case PREVISAO_EMBARQUE_FABRICA = 'previsao_embarque_fabrica';
    case EMBARQUE_FABRICA = 'embarque_fabrica';
    case NOTA_RECEBIDA_COMPRA = 'nota_recebida_compra';
    case PREVISAO_ENTREGA_ESTOQUE = 'previsao_entrega_estoque';
    case ENTREGA_ESTOQUE = 'entrega_estoque';
    case PREVISAO_ENVIO_CLIENTE = 'previsao_envio_cliente';
    case ENVIO_CLIENTE = 'envio_cliente';
    case ENTREGA_CLIENTE = 'entrega_cliente';
    case CONSIGNADO = 'consignado';
    case DEVOLUCAO_CONSIGNACAO = 'devolucao_consignacao';
    case FINALIZADO = 'finalizado';

    public function label(): string
    {
        return match ($this) {
            self::PEDIDO_CRIADO => 'Pedido Criado',
            self::ENVIADO_FABRICA => 'Enviado à Fábrica',
            self::NOTA_EMITIDA => 'Nota Emitida',
            self::PREVISAO_EMBARQUE_FABRICA => 'Previsão de Embarque',
            self::EMBARQUE_FABRICA => 'Embarque da Fábrica',
            self::NOTA_RECEBIDA_COMPRA => 'Nota Recebida (Compra)',
            self::PREVISAO_ENTREGA_ESTOQUE => 'Previsão de Entrega ao Estoque',
            self::ENTREGA_ESTOQUE => 'Entrega ao Estoque',
            self::PREVISAO_ENVIO_CLIENTE => 'Previsão de Envio ao Cliente',
            self::ENVIO_CLIENTE => 'Envio ao Cliente',
            self::ENTREGA_CLIENTE => 'Entrega ao Cliente',
            self::CONSIGNADO => 'Consignado',
            self::DEVOLUCAO_CONSIGNACAO => 'Devolução Consignação',
            self::FINALIZADO => 'Finalizado',
        };
    }
}
