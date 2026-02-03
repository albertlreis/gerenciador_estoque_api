<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CentroCustoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'nome' => (string) $this->nome,
            'slug' => (string) $this->slug,
            'centro_custo_pai_id' => $this->centro_custo_pai_id ? (int)$this->centro_custo_pai_id : null,
            'ordem' => $this->ordem !== null ? (int)$this->ordem : null,
            'ativo' => (bool) $this->ativo,
            'padrao' => (bool) $this->padrao,
            'meta_json' => $this->meta_json,

            // compat
            'label' => (string) $this->nome,
            'value' => (int) $this->id,
        ];
    }
}
