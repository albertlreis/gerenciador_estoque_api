<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AssistenciaChamadoLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status_de' => $this->status_de,
            'status_para' => $this->status_para,
            'mensagem' => $this->mensagem,
            'meta' => $this->meta_json,
            'usuario_id' => $this->usuario_id,
            'created_at' => optional($this->created_at)->toDateTimeString(),
        ];
    }
}
