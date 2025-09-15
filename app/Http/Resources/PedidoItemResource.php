<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PedidoItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'produto_id' => $this->variacao->produto_id ?? null,
            'nome_produto' => $this->variacao->produto->nome ?? '-',
            'referencia' => $this->variacao->referencia ?? '-',
            'quantidade' => $this->quantidade,
            'preco_unitario' => $this->preco_unitario,
            'subtotal' => $this->subtotal,
            'imagem' => $this->variacao->produto->imagens->first()->url_completa ?? null,
            'atributos' => AtributoResource::collection($this->variacao->atributos),
        ];
    }
}
