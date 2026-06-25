<?php

namespace App\Integrations\ContaAzul\Mappers;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Models\ContaPagar;

class ContaAzulContaPagarMapper
{
    /**
     * @return array<string, mixed>
     */
    public function fromLocal(ContaPagar $conta, ?int $lojaId = null): array
    {
        if ($conta->exists || $conta->fornecedor_id || $conta->relationLoaded('fornecedor')) {
            $conta->loadMissing(['fornecedor', 'categoria', 'centroCusto']);
        }

        $idFornecedorExt = null;
        if ($conta->fornecedor_id) {
            $idFornecedorExt = ContaAzulMapeamento::idExternoPorLocal(
                ContaAzulEntityType::FORNECEDOR,
                (int) $conta->fornecedor_id,
                $lojaId
            );
        }

        return array_filter([
            'descricao' => $conta->descricao,
            'numero_documento' => $conta->numero_documento,
            'valor' => (float) $conta->valor_liquido,
            'data_emissao' => $conta->data_emissao?->format('Y-m-d'),
            'data_vencimento' => $conta->data_vencimento?->format('Y-m-d'),
            'forma_pagamento' => $conta->forma_pagamento,
            'idFornecedor' => $idFornecedorExt,
        ], fn ($value) => $value !== null && $value !== '');
    }
}

