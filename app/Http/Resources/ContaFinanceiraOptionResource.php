<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContaFinanceiraOptionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'     => $this->id,
            'nome'   => $this->nome,
            'slug'   => $this->slug,
            'tipo'   => $this->tipo,
            'ativo'  => (bool) $this->ativo,
            'padrao' => (bool) $this->padrao,
            'moeda'  => $this->moeda,
        ];
    }
}
