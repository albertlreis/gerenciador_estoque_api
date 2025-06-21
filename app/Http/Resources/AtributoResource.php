<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AtributoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'atributo' => $this->atributo,
            'valor' => $this->valor,
        ];
    }
}
