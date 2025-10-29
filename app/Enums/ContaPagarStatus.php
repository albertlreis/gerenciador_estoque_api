<?php

namespace App\Enums;

enum ContaPagarStatus: string
{
    case ABERTA = 'ABERTA';
    case PARCIAL = 'PARCIAL';
    case PAGA = 'PAGA';
    case CANCELADA = 'CANCELADA';
}
