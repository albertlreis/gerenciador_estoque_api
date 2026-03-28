<?php

namespace App\Enums;

enum ImportacaoNormalizadaConflitoSeveridade: string
{
    case AVISO = 'aviso';
    case CONFLITO = 'conflito';
    case BLOQUEANTE = 'bloqueante';

    public function label(): string
    {
        return match ($this) {
            self::AVISO => 'Aviso',
            self::CONFLITO => 'Conflito',
            self::BLOQUEANTE => 'Bloqueante',
        };
    }
}
