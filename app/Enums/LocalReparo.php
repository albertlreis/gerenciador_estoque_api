<?php
namespace App\Enums;

enum LocalReparo: string
{
    case DEPOSITO = 'deposito';
    case FABRICA  = 'fabrica';
    case CLIENTE  = 'cliente';
}
