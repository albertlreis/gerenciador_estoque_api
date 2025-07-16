<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property int $estoque_id
 * @property string|null $corredor
 * @property string|null $prateleira
 * @property string|null $coluna
 * @property string|null $nivel
 * @property string|null $observacoes
 * @property \App\Models\Estoque|null $estoque
 */
class LocalizacaoEstoqueResource extends JsonResource
{
    /**
     * Transforma o recurso em um array para resposta JSON.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'estoque_id' => $this->estoque_id,
            'corredor' => $this->corredor,
            'prateleira' => $this->prateleira,
            'coluna' => $this->coluna,
            'nivel' => $this->nivel,
            'observacoes' => $this->observacoes,

            // Dados detalhados do estoque
            'estoque' => new EstoqueResource($this->whenLoaded('estoque')),
        ];
    }
}
