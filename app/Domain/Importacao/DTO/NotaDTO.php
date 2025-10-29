<?php

namespace App\Domain\Importacao\DTO;

final class NotaDTO
{
    public function __construct(
        public string  $numero,
        public ?string $dataEmissao,
        public ?string $fornecedorCnpj,
        public ?string $fornecedorNome,
    ) {}
}
