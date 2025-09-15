<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ParceiroResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'nome'        => $this->nome,
            'tipo'        => $this->tipo,
            'documento'   => $this->documento,
            'email'       => $this->email,
            'telefone'    => $this->telefone,
            'endereco'    => $this->endereco,
            'status'      => (int) ($this->status ?? 1),
            'observacoes' => $this->observacoes,
            'created_at'  => optional($this->created_at)->toIso8601String(),
            'updated_at'  => optional($this->updated_at)->toIso8601String(),
            'deleted_at'  => optional($this->deleted_at)->toIso8601String(),
        ];
    }
}
