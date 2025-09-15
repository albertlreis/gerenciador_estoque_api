<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property string $status
 * @property int|null $usuario_id
 * @property string|null $observacao
 * @property \Carbon\Carbon|string $created_at
 */
class PedidoFabricaStatusHistoricoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => $this->id,
            'status'     => $this->status,
            'usuario_id' => $this->usuario_id,
            'observacao' => $this->observacao,
            'data'       => optional($this->created_at)?->toDateTimeString(),
        ];
    }
}
