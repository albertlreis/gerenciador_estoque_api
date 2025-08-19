<?php

namespace App\Enums;

enum PrioridadeChamado: string
{
    case BAIXA = 'baixa';
    case MEDIA = 'media';
    case ALTA = 'alta';
    case CRITICA = 'critica';
}
