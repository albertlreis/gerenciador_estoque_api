<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property int $id_usuario
 * @property int|null $id_cliente
 * @property int|null $id_parceiro
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CarrinhoResource extends JsonResource
{
    /**
     * Transforma o recurso em um array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'id_usuario'   => $this->id_usuario,
            'id_cliente'   => $this->id_cliente,
            'id_parceiro'  => $this->id_parceiro,
            'status'       => $this->status,
            'created_at'   => optional($this->created_at)->toIso8601String(),
            'updated_at'   => optional($this->updated_at)->toIso8601String(),
            'cliente'      => new ClienteResource($this->whenLoaded('cliente')),
            'itens'        => CarrinhoItemResource::collection($this->whenLoaded('itens')),
        ];
    }
}
