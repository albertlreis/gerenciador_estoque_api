<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CarrinhoItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'id_carrinho' => $this->id_carrinho,
            'id_variacao' => $this->id_variacao,
            'quantidade' => $this->quantidade,
            'preco_unitario' => $this->preco_unitario,
            'subtotal' => $this->subtotal,
            'id_deposito' => $this->id_deposito,

            'nome_produto' => $this->nome_produto,
            'nome_completo' => $this->nome_completo,

            'variacao' => [
                'id' => $this->variacao->id ?? null,
                'referencia' => $this->variacao->referencia ?? null,
                'nome_completo' => $this->variacao->nome_completo ?? null,
                'preco' => $this->variacao->preco ?? null,
                'estoque_total' => $this->variacao->estoque_total ?? 0,
                'atributos' => $this->variacao->relationLoaded('atributos')
                    ? $this->variacao->atributos->map(fn($a) => [
                        'atributo' => $a->atributo,
                        'valor' => $a->valor,
                    ])
                    : [],
                'produto' => $this->variacao->relationLoaded('produto')
                    ? [
                        'nome' => $this->variacao->produto->nome,
                        'imagem' => $this->variacao->produto->imagemPrincipal->url ?? null
                    ]
                    : null,
            ],

            'deposito' => $this->whenLoaded('deposito', function () {
                return [
                    'id' => $this->deposito->id,
                    'nome' => $this->deposito->nome,
                ];
            }),
        ];
    }
}
