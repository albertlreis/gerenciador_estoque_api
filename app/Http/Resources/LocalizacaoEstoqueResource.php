<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LocalizacaoEstoqueResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'deposito_id' => $this->deposito_id,
            'area' => $this->area,
            'corredor' => $this->corredor,
            'setor' => $this->setor,
            'coluna' => $this->coluna,
            'nivel' => $this->nivel,
            'codigo_composto' => $this->codigo_composto,
            'observacoes' => $this->observacoes,
            'ativo' => (bool) $this->ativo,
            'ocupacao' => [
                'itens' => (int) ($this->ocupacao_itens ?? 0),
                'pecas' => (int) ($this->ocupacao_pecas ?? 0),
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
