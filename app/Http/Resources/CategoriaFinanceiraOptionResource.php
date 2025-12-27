<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CategoriaFinanceiraOptionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => (int) $this->id,
            'nome'            => (string) $this->nome,
            'slug'            => (string) $this->slug,
            'tipo'            => (string) $this->tipo,
            'ativo'           => (bool) $this->ativo,
            'padrao'          => (bool) $this->padrao,
            'categoria_pai_id'=> $this->categoria_pai_id ? (int) $this->categoria_pai_id : null,
            'ordem'           => $this->ordem !== null ? (int) $this->ordem : null,

            // compat opcional p/ dropdowns genéricos
            'label'           => (string) $this->nome,
            'value'           => (int) $this->id,
        ];
    }
}
