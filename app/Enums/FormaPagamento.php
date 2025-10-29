<?php

namespace App\Enums;

enum FormaPagamento: string
{
    case PIX = 'PIX';
    case BOLETO = 'BOLETO';
    case TED = 'TED';
    case DINHEIRO = 'DINHEIRO';
    case CARTAO = 'CARTAO';
}
