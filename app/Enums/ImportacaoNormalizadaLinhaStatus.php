<?php

namespace App\Enums;

enum ImportacaoNormalizadaLinhaStatus: string
{
    case STAGED = 'staged';
    case PENDENTE_REVISAO = 'pendente_revisao';
    case AGUARDANDO_EFETIVACAO = 'aguardando_efetivacao';
    case BLOQUEADA = 'bloqueada';
    case IGNORADA = 'ignorada';
    case EFETIVADA = 'efetivada';
    case FALHA_EFETIVACAO = 'falha_efetivacao';
    case ERRO = 'erro';

    public function label(): string
    {
        return match ($this) {
            self::STAGED => 'Staged',
            self::PENDENTE_REVISAO => 'Pendente de revisão',
            self::AGUARDANDO_EFETIVACAO => 'Aguardando efetivação',
            self::BLOQUEADA => 'Bloqueada',
            self::IGNORADA => 'Ignorada',
            self::EFETIVADA => 'Efetivada',
            self::FALHA_EFETIVACAO => 'Falha na efetivação',
            self::ERRO => 'Erro',
        };
    }
}
