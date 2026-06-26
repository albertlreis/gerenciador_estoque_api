<?php

namespace App\Integrations\ContaAzul\Mappers;

use App\Models\ContaPagarPagamento;

class ContaAzulBaixaContaPagarMapper
{
    /**
     * @return array<string, mixed>
     */
    public function fromLocal(ContaPagarPagamento $pagamento, ?int $lojaId = null): array
    {
        $pagamento->loadMissing('conta');

        return array_filter([
            'valor' => (float) $pagamento->valor,
            'data_pagamento' => $pagamento->data_pagamento?->format('Y-m-d'),
            'forma_pagamento' => $pagamento->forma_pagamento,
        ], fn ($value) => $value !== null && $value !== '');
    }
}

