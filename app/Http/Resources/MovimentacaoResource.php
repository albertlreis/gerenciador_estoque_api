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
            'data_movimentacao' => $this->data_movimentacao,
            'produto_id' => $this->variacao?->produto?->id,
            'produto_nome' => $this->variacao?->nome_completo,
            'produto_referencia' => $this->variacao?->referencia,
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
