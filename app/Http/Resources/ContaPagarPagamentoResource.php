<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ContaPagarPagamento */
class ContaPagarPagamentoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'data_pagamento' => optional($this->data_pagamento)->format('Y-m-d'),
            'valor' => (float) $this->valor,
            'forma_pagamento' => $this->forma_pagamento,
            'observacoes' => $this->observacoes,
            'comprovante_url' => $this->comprovante_path ? \Storage::url($this->comprovante_path) : null,
            'usuario' => $this->whenLoaded('usuario', fn() => [
                'id' => $this->usuario?->id,
                'name' => $this->usuario?->name,
            ]),
        ];
    }
}
