<?php

namespace App\DTOs\Assistencia;

/**
 * DTO para envio do item à assistência.
 */
class EnviarItemAssistenciaDTO
{
    public function __construct(
        public int     $itemId,
        public int     $assistenciaId,
        public int     $depositoAssistenciaId,
        public ?string $rastreioEnvio,
        public ?string $dataEnvio // Y-m-d (opcional; se null, hoje)
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            itemId: $data['item_id'],
            assistenciaId: $data['assistencia_id'],
            depositoAssistenciaId: $data['deposito_assistencia_id'],
            rastreioEnvio: $data['rastreio_envio'] ?? null,
            dataEnvio: $data['data_envio'] ?? null,
        );
    }
}
