<?php

namespace App\Enums;

enum StatusRevisaoCadastro: string
{
    case NAO_REVISADO = 'nao_revisado';
    case PENDENTE_REVISAO = 'pendente_revisao';
    case APROVADO = 'aprovado';
    case REJEITADO = 'rejeitado';

    public function label(): string
    {
        return match ($this) {
            self::NAO_REVISADO => 'Não revisado',
            self::PENDENTE_REVISAO => 'Pendente de revisão',
            self::APROVADO => 'Aprovado',
            self::REJEITADO => 'Rejeitado',
        };
    }
}
