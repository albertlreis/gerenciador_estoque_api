<?php

namespace App\Enums;

enum AssistenciaStatus: string
{
    case ABERTO                      = 'aberto';
    case AGUARDANDO_RESPOSTA_FABRICA = 'aguardando_resposta_fabrica';
    case AGUARDANDO_PECA             = 'aguardando_peca';
    case ENVIADO_FABRICA             = 'enviado_fabrica';
    case AGUARDANDO_REPARO           = 'aguardando_reparo';
    case EM_TRANSITO_RETORNO         = 'em_transito_retorno';
    case REPARO_CONCLUIDO            = 'reparo_concluido';
    case ENTREGUE                    = 'entregue';
    case CANCELADO                   = 'cancelado';
}

