<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PedidoItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'nome_produto' => $this->variacao->produto->nome ?? '-',
            'referencia' => $this->variacao->referencia ?? '-',
            'quantidade' => $this->quantidade,
            'preco_unitario' => $this->preco_unitario,
            'subtotal' => $this->subtotal,
            'imagem' => $this->variacao->produto->imagens->first()->url ?? null,
            'atributos' => AtributoResource::collection($this->variacao->atributos),
        ];
    }
}
