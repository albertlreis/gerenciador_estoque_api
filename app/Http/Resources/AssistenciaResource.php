<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AssistenciaResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'cnpj' => $this->cnpj,
            'telefone' => $this->telefone,
            'email' => $this->email,
            'contato' => $this->contato,
            'endereco' => $this->endereco_json,
            'prazo_padrao_dias' => $this->prazo_padrao_dias,
            'ativo' => (bool) $this->ativo,
            'observacoes' => $this->observacoes,
            'created_at' => optional($this->created_at)->toDateTimeString(),
        ];
    }
}
