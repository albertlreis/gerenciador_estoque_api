<?php

namespace App\Enums;

enum ContaReceberStatusEnum: string
{
    case ABERTO   = 'ABERTO';
    case PARCIAL  = 'PARCIAL';
    case RECEBIDO = 'RECEBIDO';
    case VENCIDO  = 'VENCIDO';
    case CANCELADO = 'CANCELADO';
    case ESTORNADO = 'ESTORNADO';

    public function label(): string
    {
        return match ($this) {
            self::ABERTO    => 'Aberto',
            self::PARCIAL   => 'Parcial',
            self::RECEBIDO  => 'Recebido',
            self::VENCIDO   => 'Vencido',
            self::CANCELADO => 'Cancelado',
            self::ESTORNADO => 'Estornado',
        };
    }

    public static function fromDb(?string $value): self
    {
        $value = strtoupper(trim((string)$value));
        return self::tryFrom($value) ?? self::ABERTO;
    }
}
