<?php

namespace App\Enums;

enum AssistenciaStatus: string
{
    case ABERTO = 'aberto';
    case EM_ANALISE = 'em_analise';
    case ENVIADO_ASSISTENCIA = 'enviado_assistencia';
    case EM_ORCAMENTO = 'em_orcamento';
    case ORCAMENTO_APROVADO = 'orcamento_aprovado';
    case EM_REPARO = 'em_reparo';
    case SUBSTITUICAO_AUTORIZADA = 'substituicao_autorizada';
    case DEVOLVIDO_FORNECEDOR = 'devolvido_fornecedor';
    case RETORNADO = 'retornado';
    case FINALIZADO = 'finalizado';
    case CANCELADO = 'cancelado';
}
