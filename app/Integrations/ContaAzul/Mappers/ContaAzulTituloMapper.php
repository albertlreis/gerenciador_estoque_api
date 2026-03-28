<?php

namespace App\Integrations\ContaAzul\Mappers;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Models\ContaReceber;

/**
 * Mapeia {@see ContaReceber} (título no Sierra: uma linha em contas_receber com vencimento e valores).
 * Não existe modelo paralelo de “título Conta Azul” no domínio local.
 */
class ContaAzulTituloMapper
{
    /**
     * @return array<string, mixed>
     */
    public function fromLocal(ContaReceber $conta, ?int $lojaId = null): array
    {
        $conta->loadMissing(['pedido.cliente']);

        // Valor líquido segue o accessor do modelo Sierra (bruto − desconto + juros + multa).
        $liq = (float) $conta->valor_liquido;

        $idVendaExt = null;
        $idClienteExt = null;
        if ($conta->pedido_id) {
            $idVendaExt = ContaAzulMapeamento::idExternoPorLocal(
                ContaAzulEntityType::VENDA,
                (int) $conta->pedido_id,
                $lojaId
            );
        }
        $cid = $conta->pedido?->id_cliente;
        if ($cid) {
            $idClienteExt = ContaAzulMapeamento::idExternoPorLocal(
                ContaAzulEntityType::PESSOA,
                (int) $cid,
                $lojaId
            );
        }

        return array_filter([
            'descricao' => $conta->descricao,
            'numero_documento' => $conta->numero_documento,
            'valor' => $liq,
            'data_emissao' => $conta->data_emissao?->format('Y-m-d'),
            'data_vencimento' => $conta->data_vencimento?->format('Y-m-d'),
            'idVenda' => $idVendaExt,
            'idCliente' => $idClienteExt,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
