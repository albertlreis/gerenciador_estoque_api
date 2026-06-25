<?php

namespace App\Integrations\ContaAzul\Mappers;

use App\Models\ContaReceber;

class ContaAzulCobrancaMapper
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function boletoFromLocal(ContaReceber $conta, string $idTituloExterno, array $options = []): array
    {
        $eventoIdField = (string) ($options['evento_id_field'] ?? 'id_evento_financeiro');
        $tipoPagamento = (string) ($options['tipo_pagamento'] ?? 'BOLETO');
        $maximoParcelas = max(1, (int) ($options['maximo_parcelas'] ?? 1));

        return array_filter([
            $eventoIdField => $idTituloExterno,
            'tipo' => $tipoPagamento,
            'maximo_parcelas' => $maximoParcelas,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
