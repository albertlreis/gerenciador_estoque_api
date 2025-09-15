<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FornecedorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'nome'        => $this->nome,
            'cnpj'        => $this->cnpj,
            'email'       => $this->email,
            'telefone'    => $this->telefone,
            'endereco'    => $this->endereco,
            'status'      => (int) $this->status,
            'observacoes' => $this->observacoes,
            'produtos_count' => $this->whenCounted('produtos'),
            'created_at'  => optional($this->created_at)->toIso8601String(),
            'updated_at'  => optional($this->updated_at)->toIso8601String(),
            'deleted_at'  => optional($this->deleted_at)->toIso8601String(),
        ];
    }
}
