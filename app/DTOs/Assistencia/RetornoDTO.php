<?php

namespace App\DTOs\Assistencia;

/**
 * DTO para registrar retorno do item da assistência.
 */
class RetornoDTO
{
    public function __construct(
        public int     $itemId,
        public int     $depositoRetornoId,
        public ?string $rastreioRetorno,
        public ?string $dataRetorno // Y-m-d (opcional; se null, hoje)
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            itemId: $data['item_id'],
            depositoRetornoId: $data['deposito_retorno_id'],
            rastreioRetorno: $data['rastreio_retorno'] ?? null,
            dataRetorno: $data['data_retorno'] ?? null,
        );
    }
}
