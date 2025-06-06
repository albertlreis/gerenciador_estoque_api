<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MovimentacaoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'quantidade' => $this->quantidade,
            'data_movimentacao' => optional($this->data_movimentacao)->format('d/m/Y'),

            'produto_id' => $this->produto?->id,
            'produto_nome' => $this->produto?->nome,
            'produto_referencia' => $this->produto?->referencia,

            'deposito_origem_id' => $this->depositoOrigem?->id,
            'deposito_origem_nome' => $this->depositoOrigem?->nome,

            'deposito_destino_id' => $this->depositoDestino?->id,
            'deposito_destino_nome' => $this->depositoDestino?->nome,

            'usuario_id' => $this->usuario?->id,
            'usuario_nome' => $this->usuario?->nome,

            'observacoes' => $this->observacoes,
        ];
    }
}
