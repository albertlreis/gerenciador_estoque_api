<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PedidoItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'variacao_id' => $this->id_variacao,
            'produto_id' => $this->variacao->produto_id ?? null,
            'nome_produto' => $this->variacao->produto->nome ?? '-',
            'referencia' => $this->variacao->referencia ?? '-',
            'quantidade' => $this->quantidade,
            'preco_unitario' => $this->preco_unitario,
            'subtotal' => $this->subtotal,
            'id_deposito' => $this->id_deposito,
            'observacoes' => $this->observacoes,
            'imagem' => $this->variacao->produto->imagens->first()->url_completa ?? null,
            'atributos' => AtributoResource::collection($this->variacao->atributos),
        ];
    }
}
