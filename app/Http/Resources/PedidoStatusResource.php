<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PedidoStatusResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'status' => $this->status,
            'label' => $this->status ? ucwords(str_replace('_', ' ', $this->status->name)) : '—',
            'data_status' => $this->data_status,
            'observacoes' => $this->observacoes,
            'usuario' => $this->usuario->nome ?? '—',
        ];
    }
}
