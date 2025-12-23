<?php

namespace App\Enums;

enum ContaStatus: string
{
    case ABERTA = 'ABERTA';
    case PARCIAL = 'PARCIAL';
    case PAGA = 'PAGA';
    case CANCELADA = 'CANCELADA';
}
