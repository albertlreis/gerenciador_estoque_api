<?php

namespace App\Services;

use App\Models\Consignacao;
use App\Models\Pedido;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Criação de registros de consignação a partir de itens do carrinho.
 */
final class ConsignacaoFactory
{
    /**
     * @param  Pedido     $pedido
     * @param  Collection $itensCarrinho
     * @param  array      $depositosMap
     * @param  Carbon     $prazoResposta
     * @return void
     */
    public function criarLote(Pedido $pedido, Collection $itensCarrinho, array $depositosMap, Carbon $prazoResposta): void
    {
        foreach ($itensCarrinho as $item) {
            $depId = $depositosMap[$item->id] ?? $item->id_deposito ?? null;

            Consignacao::create([
                'pedido_id'           => $pedido->id,
                'produto_variacao_id' => $item->id_variacao,
                'deposito_id'         => $depId,
                'quantidade'          => $item->quantidade,
                'data_envio'          => now('America/Belem'),
                'prazo_resposta'      => $prazoResposta->copy()->startOfDay(),
                'status'              => 'pendente',
            ]);
        }
    }
}
