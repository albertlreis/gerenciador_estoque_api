<?php

namespace App\DTOs\Assistencia;

/**
 * DTO para registrar orçamento do item.
 */
class OrcamentoDTO
{
    public function __construct(
        public int   $itemId,
        public float $valorOrcado
        // Upload/arquivo é responsabilidade do Controller; aqui controlamos o valor
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            itemId: $data['item_id'],
            valorOrcado: (float) $data['valor_orcado'],
        );
    }
}
