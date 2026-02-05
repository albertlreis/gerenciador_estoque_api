<?php

namespace App\Enums;

enum EstoqueMovimentacaoTipo: string
{
    case ENTRADA = 'entrada';
    case SAIDA = 'saida';
    case TRANSFERENCIA = 'transferencia';
    case ESTORNO = 'estorno';
    case CONSIGNACAO_ENVIO = 'consignacao_envio';
    case CONSIGNACAO_DEVOLUCAO = 'consignacao_devolucao';

    case ASSISTENCIA_ENVIO = 'assistencia_envio';
    case ASSISTENCIA_RETORNO = 'assistencia_retorno';
    case ENTRADA_DEPOSITO         = 'entrada_deposito';
    case SAIDA_ENTREGA_CLIENTE    = 'saida_entrega_cliente';
}
