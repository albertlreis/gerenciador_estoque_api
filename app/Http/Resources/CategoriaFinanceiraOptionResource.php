<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CategoriaFinanceiraOptionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'label' => $this->nome,
            'value' => $this->id,
            'tipo'  => $this->tipo,
            'ativo' => (bool) $this->ativo,
            'raw'   => [
                'id' => $this->id,
                'nome' => $this->nome,
                'slug' => $this->slug,
                'tipo' => $this->tipo,
                'ativo' => (bool) $this->ativo,
                'padrao' => (bool) $this->padrao,
                'categoria_pai_id' => $this->categoria_pai_id,
                'ordem' => $this->ordem,
            ],
        ];
    }
}
