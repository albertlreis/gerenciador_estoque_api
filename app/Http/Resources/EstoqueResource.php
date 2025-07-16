<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property int $produto_variacao_id
 * @property int $deposito_id
 * @property float|int $quantidade
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property \App\Models\ProdutoVariacao $variacao
 * @property \App\Models\Deposito|null $deposito
 */
class EstoqueResource extends JsonResource
{
    /**
     * Transforma o recurso em um array para resposta JSON.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'produto_variacao_id' => $this->produto_variacao_id,
            'deposito_id' => $this->deposito_id,
            'quantidade' => $this->quantidade,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Relacionamentos
            'variacao' => [
                'id' => $this->variacao->id,
                'referencia' => $this->variacao->referencia,
                'atributos' => $this->variacao->atributos->map(function ($atributo) {
                    return [
                        'nome' => $atributo->nome,
                        'valor' => $atributo->pivot->valor,
                    ];
                }),
                'produto' => [
                    'id' => $this->variacao->produto->id,
                    'nome' => $this->variacao->produto->nome,
                    'referencia' => $this->variacao->produto->referencia,
                    'categoria' => [
                        'id' => optional($this->variacao->produto->categoria)->id,
                        'nome' => optional($this->variacao->produto->categoria)->nome,
                    ],
                ],
            ],

            'deposito' => [
                'id' => optional($this->deposito)->id,
                'nome' => optional($this->deposito)->nome,
            ],
        ];
    }
}
