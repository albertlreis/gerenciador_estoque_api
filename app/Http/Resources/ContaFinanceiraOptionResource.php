<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContaFinanceiraOptionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'     => (int) $this->id,
            'nome'   => (string) $this->nome,
            'slug'   => (string) $this->slug,
            'tipo'   => (string) $this->tipo,
            'ativo'  => (bool) $this->ativo,
            'padrao' => (bool) $this->padrao,
            'moeda'  => (string) $this->moeda,

            'label'  => (string) $this->nome,
            'value'  => (int) $this->id,
        ];
    }
}
