<?php

namespace App\DTOs;

class FiltroContaPagarDTO
{
    public function __construct(
        public readonly ?string $busca = null,
        public readonly ?int $fornecedor_id = null,
        public readonly ?string $status = null,
        public readonly ?string $centro_custo = null,
        public readonly ?string $categoria = null,
        public readonly ?string $data_ini = null,
        public readonly ?string $data_fim = null,
        public readonly ?bool $vencidas = null,
    ) {}
}
