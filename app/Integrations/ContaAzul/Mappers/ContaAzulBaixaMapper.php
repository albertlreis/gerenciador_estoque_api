<?php

namespace App\Integrations\ContaAzul\Mappers;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
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

        $idTituloExt = ContaAzulMapeamento::idExternoPorLocal(
            ContaAzulEntityType::TITULO,
            (int) $pagamento->conta_receber_id,
            $lojaId
        );

        return array_filter([
            'idTitulo' => $idTituloExt,
            'valor' => (float) $pagamento->valor,
            'dataPagamento' => $pagamento->data_pagamento?->format('Y-m-d'),
            'formaPagamento' => $pagamento->forma_pagamento?->value,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
