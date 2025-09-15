<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property int $pedido_fabrica_item_id
 * @property int|null $deposito_id
 * @property int $quantidade
 * @property string|null $observacao
 * @property \Carbon\Carbon $created_at
 */
class PedidoFabricaEntregaResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => $this->id,
            'item_id'    => $this->pedido_fabrica_item_id,
            'deposito'   => $this->deposito?->only(['id','nome']),
            'quantidade' => $this->quantidade,
            'observacao' => $this->observacao,
            'created_at' => optional($this->created_at)?->toDateTimeString(),
        ];
    }
}
