<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoVariacaoAtributoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'atributo' => $this->atributo,
            'valor' => $this->valor,
        ];
    }
}
