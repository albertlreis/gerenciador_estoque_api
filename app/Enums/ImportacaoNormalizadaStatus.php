<?php

namespace App\Enums;

enum ImportacaoNormalizadaStatus: string
{
    case RECEBIDA = 'recebida';
    case STAGED = 'staged';
    case EM_REVISAO = 'em_revisao';
    case PRONTA_PARA_EFETIVAR = 'pronta_para_efetivar';
    case CONFIRMADA = 'confirmada';
    case EM_PROCESSAMENTO = 'em_processamento';
    case EFETIVADA = 'efetivada';
    case ERRO = 'erro';
    case CANCELADA = 'cancelada';

    public function label(): string
    {
        return match ($this) {
            self::RECEBIDA => 'Recebida',
            self::STAGED => 'Staged',
            self::EM_REVISAO => 'Em revisão',
            self::PRONTA_PARA_EFETIVAR => 'Pronta para efetivar',
            self::CONFIRMADA => 'Confirmada',
            self::EM_PROCESSAMENTO => 'Em processamento',
            self::EFETIVADA => 'Efetivada',
            self::ERRO => 'Erro',
            self::CANCELADA => 'Cancelada',
        };
    }
}
