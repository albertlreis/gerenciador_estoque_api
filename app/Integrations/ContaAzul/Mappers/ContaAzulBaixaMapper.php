<?php

namespace App\Integrations\ContaAzul\Mappers;

use App\Models\ContaReceberPagamento;

/**
 * Mapeia {@see ContaReceberPagamento} (baixa = pagamento registrado no Sierra contra {@see ContaReceber}).
 */
class ContaAzulBaixaMapper
{
    /**
     * @return array<string, mixed>
     */
    public function fromLocal(ContaReceberPagamento $pagamento, ?int $lojaId = null): array
    {
        $pagamento->loadMissing('conta');

        return array_filter([
            'valor' => (float) $pagamento->valor,
            'data_pagamento' => $pagamento->data_pagamento?->format('Y-m-d'),
            'forma_pagamento' => $pagamento->forma_pagamento?->value,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
