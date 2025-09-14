<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource da localização de estoque (essencial + dimensões).
 */
class LocalizacaoEstoqueResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'estoque_id'     => $this->estoque_id,
            'setor'          => $this->setor,
            'coluna'         => $this->coluna,
            'nivel'          => $this->nivel,
            'area_id'        => $this->area_id,
            'codigo_composto'=> $this->codigo_composto,
            'observacoes'    => $this->observacoes,
            'area'           => $this->whenLoaded('area', fn () => [
                'id' => $this->area?->id,
                'nome' => $this->area?->nome,
            ]),
            'dimensoes'      => $this->whenLoaded('valores', function () {
                return $this->valores->map(fn($v) => [
                    'dimensao_id' => $v->dimensao_id,
                    'nome'        => $v->dimensao?->nome,
                    'valor'       => $v->valor,
                ]);
            })
        ];
    }
}
