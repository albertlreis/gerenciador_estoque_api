<?php

namespace App\Domain\Importacao\DTO;

final class AtributoDTO
{
    public function __construct(
        public string $atributo,
        public string $valor,
    ) {}
}
