<?php

namespace App\Enums;

enum EstoqueMovimentacaoTipo: string
{
    case ENTRADA = 'entrada';
    case SAIDA = 'saida';
    case TRANSFERENCIA = 'transferencia';
    case CONSIGNACAO_ENVIO = 'consignacao_envio';
    case CONSIGNACAO_DEVOLUCAO = 'consignacao_devolucao';

    case ASSISTENCIA_ENVIO = 'assistencia_envio';
    case ASSISTENCIA_RETORNO = 'assistencia_retorno';
}
