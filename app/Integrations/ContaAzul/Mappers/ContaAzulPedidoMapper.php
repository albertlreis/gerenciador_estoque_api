<?php

namespace App\Integrations\ContaAzul\Mappers;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Models\Pedido;
use App\Models\PedidoItem;

class ContaAzulPedidoMapper
{
    /**
     * @return array<string, mixed>
     */
    public function fromLocal(Pedido $pedido, ?int $lojaId = null): array
    {
        $idClienteExt = null;
        if ($pedido->id_cliente) {
            $idClienteExt = ContaAzulMapeamento::idExternoPorLocal(
                ContaAzulEntityType::PESSOA,
                (int) $pedido->id_cliente,
                $lojaId
            );
        }

        $itens = [];
        foreach ($pedido->itens as $item) {
            $item instanceof PedidoItem;
            $produtoId = $item->variacao?->produto_id;
            $idProdutoExt = $produtoId
                ? ContaAzulMapeamento::idExternoPorLocal(ContaAzulEntityType::PRODUTO, (int) $produtoId, $lojaId)
                : null;
            $itens[] = array_filter([
                'id' => $idProdutoExt,
                'referencia' => $item->variacao?->referencia,
                'quantidade' => (float) $item->quantidade,
                'valor' => (float) $item->preco_unitario,
            ], fn ($v) => $v !== null);
        }

        return array_filter([
            'id_cliente' => $idClienteExt,
            'numero' => $pedido->numero_externo ?? (string) $pedido->id,
            'data_venda' => $pedido->data_pedido?->format('Y-m-d'),
            'situacao' => 'APROVADO',
            'valor_total' => (float) $pedido->valor_total,
            'itens' => $itens,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
