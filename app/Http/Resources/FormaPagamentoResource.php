<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FormaPagamentoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'nome' => (string) $this->nome,
            'slug' => (string) $this->slug,
            'ativo' => (bool) $this->ativo,
            'label' => (string) $this->nome,
            'value' => (string) $this->nome,
        ];
    }
}
