<?php

namespace App\DTOs\Assistencia;

/**
 * DTO para aprovar/reprovar orçamento.
 */
class AprovacaoDTO
{
    public function __construct(
        public int     $itemId,
        public bool    $aprovado,
        public ?string $observacao
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            itemId: $data['item_id'],
            aprovado: (bool) $data['aprovado'],
            observacao: $data['observacao'] ?? null,
        );
    }
}
