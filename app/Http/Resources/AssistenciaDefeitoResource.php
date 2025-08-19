<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AssistenciaDefeitoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'descricao' => $this->descricao,
            'critico' => (bool) $this->critico,
            'ativo' => (bool) $this->ativo,
        ];
    }
}
