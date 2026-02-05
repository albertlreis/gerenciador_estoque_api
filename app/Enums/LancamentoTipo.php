<?php

namespace App\Enums;

enum LancamentoTipo: string
{
    case RECEITA = 'receita';
    case DESPESA = 'despesa';
    case TRANSFERENCIA = 'transferencia';
    case AJUSTE = 'ajuste';
}
