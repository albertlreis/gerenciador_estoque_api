<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CarrinhoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'id_usuario' => $this->id_usuario,
            'id_cliente' => $this->id_cliente,
            'id_parceiro' => $this->id_parceiro,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'cliente' => $this->whenLoaded('cliente'),
            'itens' => CarrinhoItemResource::collection($this->whenLoaded('itens')),
        ];
    }
}
