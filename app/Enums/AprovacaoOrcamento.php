<?php

namespace App\Enums;

enum AprovacaoOrcamento: string
{
    case PENDENTE = 'pendente';
    case APROVADO = 'aprovado';
    case REPROVADO = 'reprovado';
}
