<?php

namespace App\Enums;

enum LancamentoStatus: string
{
    case CONFIRMADO = 'confirmado';
    case CANCELADO  = 'cancelado';
}
